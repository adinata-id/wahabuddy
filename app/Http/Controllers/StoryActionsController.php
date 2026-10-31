<?php

namespace App\Http\Controllers;

use App\Actions\Stories\ResolveStory;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
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
