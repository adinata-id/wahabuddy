<?php

namespace App\Jobs;

use App\Actions\Broadcasts\ClaimRecipient;
use App\Actions\Broadcasts\CompleteRecipient;
use App\Enums\BroadcastStatus;
use App\Enums\BroadcastType;
use App\Enums\RecipientStatus;
use App\Enums\StoryStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Story;
use App\Services\Media\MediaUrlResolver;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Processes exactly ONE recipient per execution (spec I): never a long HTTP
 * loop, never per-recipient random delays stacked in the queue. Pacing is
 * campaign next_send_at + the shared session gate; the next job is delayed
 * accordingly. tries=1 — persistence retries are domain-level.
 */
class ProcessBroadcastJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $broadcastId) {}

    public function handle(
        ConnectionRepository $connections,
        ClaimRecipient $claim,
        CompleteRecipient $complete,
        WahaClient $client,
        SessionSendGate $gate,
        MediaUrlResolver $urls,
    ): void {
        $broadcast = Broadcast::find($this->broadcastId);

        if ($broadcast === null || ! in_array($broadcast->status, [BroadcastStatus::Queued, BroadcastStatus::Running], true)) {
            return; // paused/canceled/terminal jobs are deliberate no-ops
        }

        // Story sends take priority when due (spec I).
        $storiesDue = Story::query()
            ->whereIn('status', [StoryStatus::Pending, StoryStatus::Failed])
            ->where('scheduled_at', '<=', now())
            ->exists();

        $snapshot = $connections->snapshot();

        $lease = $gate->acquire($snapshot);
        if ($lease === null) {
            $this->releaseLater(5);

            return;
        }

        try {
            if ($storiesDue || ($gate->nextAllowedAt($snapshot)?->isFuture() ?? false)) {
                $this->releaseLater(10);

                return;
            }

            $claimed = $claim->handle($this->broadcastId, $snapshot);

            if ($claimed === null) {
                $this->advanceCampaign($broadcast);

                return;
            }

            ['recipient' => $recipient, 'token' => $token] = $claimed;

            $mediaUrl = $broadcast->type === BroadcastType::Image && $broadcast->mediaFile !== null
                ? $urls->forSend($broadcast->mediaFile)
                : null;

            try {
                $result = $client->sendBroadcast($snapshot, $recipient, $broadcast, $mediaUrl);
                $complete->handle($recipient->id, $token, $result);
            } catch (WahaRequestException $exception) {
                $complete->handle($recipient->id, $token, $exception);
            }

            $broadcast->refresh();
            $this->advanceCampaign($broadcast);
        } finally {
            $lease->release();
        }
    }

    /**
     * Queue the next single-recipient execution (or complete the campaign).
     * Campaign-completion is derived from recipient aggregation, never a
     * local counter, so counts cannot drift (spec I).
     */
    private function advanceCampaign(Broadcast $broadcast): void
    {
        // Retryable failures (backoff window set) still owe a send; only
        // terminal rows — sent, failed without retry, failed_max, canceled —
        // count toward completion ("completed with failures" is allowed).
        $remaining = $broadcast->recipients()
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereIn('status', [RecipientStatus::Pending, RecipientStatus::Sending, RecipientStatus::Unknown])
                        ->orWhere(function ($query): void {
                            $query->where('status', RecipientStatus::Failed)->whereNotNull('next_attempt_at');
                        });
                });
            })
            ->count();

        if ($remaining === 0) {
            if ($broadcast->finished_at === null) {
                $summaryRows = $broadcast->recipients()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->get();

                $summary = $summaryRows
                    ->map(fn (BroadcastRecipient $row) => $row->status->value.':'.$row->getAttribute('total'))
                    ->implode(' ');

                $broadcast->forceFill([
                    'status' => BroadcastStatus::Completed,
                    'finished_at' => now(),
                ])->save();

                $broadcast->logs()->create([
                    'event' => 'completed',
                    'message' => "Campaign finished. {$summary}",
                ]);
            }

            return;
        }

        // Wait for campaign pacing (or retry windows) before the next attempt.
        $earliestRetry = $broadcast->recipients()
            ->where('status', RecipientStatus::Pending->value)
            ->whereNotNull('next_attempt_at')
            ->min('next_attempt_at');

        $waitUntil = max(
            $broadcast->next_send_at?->getTimestamp() ?? 0,
            $earliestRetry !== null ? Carbon::parse($earliestRetry)->getTimestamp() : 0,
        );

        $delay = (int) max(1, $waitUntil - now()->getTimestamp());

        ProcessBroadcastJob::dispatch($broadcast->id)->delay(now()->addSeconds($delay));
    }

    private function releaseLater(int $seconds): void
    {
        if ($this->job !== null) {
            $this->release($seconds);
        }
    }
}
