<?php

namespace App\Http\Controllers;

use App\Actions\Stories\ResolveStory;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operational story actions (spec J): manual retry opens a new cycle,
 * unknown resolution is audited, cancel stops future sends, and remote
 * deletion is separate from local record handling.
 */
class StoryActionsController extends Controller
{
    /**
     * Manual retry: allowed from failed/failed_max/expired/canceled. Starts a
     * fresh attempt cycle (attempts reset), logged for audit (spec G1).
     */
    public function retry(Story $story): RedirectResponse
    {
        if (! in_array($story->status, [StoryStatus::Failed, StoryStatus::FailedMax, StoryStatus::Expired, StoryStatus::Canceled], true)) {
            return back()->withErrors(['story' => 'Only failed, expired, or canceled stories can be retried.']);
        }

        $connection = app(ConnectionRepository::class)->current();

        Story::query()
            ->whereKey($story->id)
            ->whereIn('status', [StoryStatus::Failed->value, StoryStatus::FailedMax->value, StoryStatus::Expired->value, StoryStatus::Canceled->value])
            ->update([
                'status' => StoryStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'attempt_token' => null,
                'sending_started_at' => null,
                'last_error' => null,
                'last_error_code' => null,
                // Retarget the current connection explicitly on manual retry.
                'connection_revision' => $connection->revision,
            ]);

        $story->refresh();
        $story->logs()->create([
            'event' => 'retry_manual',
            'message' => 'Admin opened a new retry cycle.',
            'context' => ['connection_revision' => $connection->revision],
        ]);

        return back()->with('status', 'story-retried');
    }

    /**
     * Resolve an unknown outcome: mark_sent (proof or admin confirmation) or
     * retry with duplication risk acknowledged. Note is mandatory.
     */
    public function resolve(Request $request, Story $story, ResolveStory $resolver): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:mark_sent,retry'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $resolver->handle($story, $validated['decision'], $validated['note']);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['story' => $exception->getMessage()]);
        }

        return back()->with('status', 'story-resolved');
    }

    /**
     * Cancel stops future sends; an in-flight request cannot be recalled and
     * may still complete (explained in the UI).
     */
    public function cancel(Story $story): RedirectResponse
    {
        if ($story->status === StoryStatus::Sending) {
            return back()->withErrors(['story' => 'The story is currently sending; the in-flight request may still complete. Wait for its outcome before acting.']);
        }

        if (! in_array($story->status, [StoryStatus::Pending, StoryStatus::Failed, StoryStatus::FailedMax, StoryStatus::Expired], true)) {
            return back()->withErrors(['story' => 'Only pending, failed, expired, or canceled-able stories can be canceled.']);
        }

        Story::query()
            ->whereKey($story->id)
            ->whereIn('status', [StoryStatus::Pending->value, StoryStatus::Failed->value, StoryStatus::FailedMax->value, StoryStatus::Expired->value])
            ->update([
                'status' => StoryStatus::Canceled->value,
                'next_attempt_at' => null,
            ]);

        $story->refresh();
        $story->logs()->create([
            'event' => 'canceled',
            'message' => 'Admin canceled the story.',
        ]);

        return back()->with('status', 'story-canceled');
    }

    /**
     * Bulk status update over a selected set (spec J extension): cancel,
     * retry, delete local records, or set an explicit status. Eligibility
     * matches the single actions exactly; ineligible rows are skipped and
     * reported, never forced.
     */
    public function bulk(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:cancel,retry,delete,set_status'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            // Sending belongs to the send job and unknown requires an
            // explicit resolution (spec G1/M), so neither is settable.
            'status' => ['required_if:action,set_status', 'in:pending,sent,failed,failed_max,expired,canceled'],
        ]);

        $connection = app(ConnectionRepository::class);
        $target = $validated['action'] === 'set_status'
            ? StoryStatus::from($validated['status'])
            : null;
        $updated = 0;
        $skipped = 0;

        Story::query()
            ->whereIn('id', $validated['ids'])
            ->get()
            ->each(function (Story $story) use ($validated, $connection, $target, &$updated, &$skipped): void {
                $eligible = match ($validated['action']) {
                    'cancel' => in_array($story->status, [StoryStatus::Pending, StoryStatus::Failed, StoryStatus::FailedMax, StoryStatus::Expired], true),
                    'retry' => in_array($story->status, [StoryStatus::Failed, StoryStatus::FailedMax, StoryStatus::Expired, StoryStatus::Canceled], true),
                    'delete' => ! in_array($story->status, [StoryStatus::Sending, StoryStatus::Unknown], true),
                    'set_status' => ! in_array($story->status, [StoryStatus::Sending, StoryStatus::Unknown], true)
                        && $story->status !== $target,
                    default => false,
                };

                if (! $eligible) {
                    $skipped++;

                    return;
                }

                if ($validated['action'] === 'delete') {
                    $story->logs()->create([
                        'event' => 'deleted_local',
                        'message' => 'Local story record deleted (bulk action).',
                    ]);
                    $story->delete();
                    $updated++;

                    return;
                }

                if ($validated['action'] === 'set_status') {
                    $old = $story->status;
                    $story->forceFill($this->overrideFields($target, $connection->current()->revision));
                    $story->save();

                    $story->logs()->create([
                        'event' => 'status_overridden',
                        'message' => "Admin set the story status from {$old->value} to {$target->value} (bulk action).",
                        'context' => ['from' => $old->value, 'to' => $target->value],
                    ]);
                    $updated++;

                    return;
                }

                $fields = $validated['action'] === 'cancel'
                    ? ['status' => StoryStatus::Canceled, 'next_attempt_at' => null]
                    : [
                        'status' => StoryStatus::Pending,
                        'attempts' => 0,
                        'next_attempt_at' => null,
                        'attempt_token' => null,
                        'sending_started_at' => null,
                        'last_error' => null,
                        'last_error_code' => null,
                        'connection_revision' => $connection->current()->revision,
                    ];

                $story->forceFill($fields);
                $story->save();

                $story->logs()->create([
                    'event' => $validated['action'] === 'cancel' ? 'canceled' : 'retry_manual',
                    'message' => $validated['action'] === 'cancel'
                        ? 'Admin canceled the story (bulk action).'
                        : 'Admin opened a new retry cycle (bulk action).',
                ]);

                $updated++;
            });

        $lead = $validated['action'] === 'set_status'
            ? "Status set to {$validated['status']} on"
            : ucfirst($validated['action']).' applied to';
        $summary = $lead." {$updated} stor".($updated === 1 ? 'y' : 'ies')
            .($skipped > 0 ? "; {$skipped} skipped (not eligible)." : '.');

        return $request->expectsJson()
            ? response()->json(['updated' => $updated, 'skipped' => $skipped, 'summary' => $summary])
            : back()->with('status', 'stories-bulk')->with('bulk_summary', $summary);
    }

    /**
     * Field payload for a manual status override. Pending re-enters the send
     * cycle exactly like a retry; sent stamps the local time without
     * fabricating WAHA ids; every other target is terminal bookkeeping.
     *
     * @return array<string, mixed>
     */
    private function overrideFields(StoryStatus $target, int $connectionRevision): array
    {
        if ($target === StoryStatus::Pending) {
            return [
                'status' => StoryStatus::Pending,
                'attempts' => 0,
                'next_attempt_at' => null,
                'attempt_token' => null,
                'sending_started_at' => null,
                'last_error' => null,
                'last_error_code' => null,
                'connection_revision' => $connectionRevision,
            ];
        }

        if ($target === StoryStatus::Sent) {
            return [
                'status' => StoryStatus::Sent,
                'sent_at' => now(),
                'attempt_token' => null,
                'sending_started_at' => null,
            ];
        }

        return [
            'status' => $target,
            'next_attempt_at' => null,
        ];
    }

    /**
     * Delete the status on WhatsApp using the stored key id. Capability and
     * target must allow it; failures surface without side effects locally.
     */
    public function deleteRemote(Story $story, WahaClient $client): RedirectResponse
    {
        if ($story->status !== StoryStatus::Sent) {
            return back()->withErrors(['story' => 'Only sent stories can be deleted remotely.']);
        }

        if ($story->waha_key_id === null || $story->waha_key_id === '') {
            return back()->withErrors(['story' => 'No status key id was stored for this story, so remote deletion cannot be requested safely.']);
        }

        $connection = app(ConnectionRepository::class);

        if ($story->connection_revision !== $connection->current()->revision) {
            return back()->withErrors(['story' => 'The WAHA target changed since this story was sent. Delete it from WhatsApp manually.']);
        }

        try {
            $client->deleteStatus($connection->snapshot(), $story->waha_key_id);
        } catch (WahaRequestException $exception) {
            return back()->withErrors(['story' => $exception->safeMessage]);
        }

        $story->forceFill(['deleted_from_waha_at' => now()])->save();
        $story->logs()->create([
            'event' => 'deleted_remote',
            'message' => 'Remote status deletion accepted by WAHA.',
            'context' => ['key_id' => $story->waha_key_id],
        ]);

        return back()->with('status', 'story-deleted-remote');
    }
}
