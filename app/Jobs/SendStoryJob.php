<?php

namespace App\Jobs;

use App\Actions\Stories\ClaimStory;
use App\Actions\Stories\CompleteStory;
use App\Enums\StoryStatus;
use App\Models\Story;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends exactly one story. Ordering (spec G2):
 * eligibility -> gate lock -> atomic claim -> HTTP -> token-guarded completion,
 * with the gate always released in finally. The job itself never retries
 * (tries=1); persistence-level retries live in the domain, not the queue.
 */
class SendStoryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $storyId) {}

    public function handle(
        ConnectionRepository $connections,
        ClaimStory $claim,
        CompleteStory $complete,
        WahaClient $client,
        SessionSendGate $gate,
        MediaUrlResolver $urls,
        MediaStorage $storage,
    ): void {
        // Configuration is read fresh per execution, never cached across jobs.
        $snapshot = $connections->snapshot();

        $story = Story::find($this->storyId);

        if ($story === null) {
            return;
        }

        if (! $claim->isEligible($story, $snapshot)) {
            return;
        }

        // Session must be verified WORKING; otherwise hold without burning
        // an attempt (spec D1).
        if ($connections->current()->last_test_status !== 'working') {
            $story->logs()->create([
                'event' => 'held',
                'message' => 'Session is not verified WORKING. Run a connection test in Settings; the story stays queued.',
            ]);

            return;
        }

        $mediaUrl = null;
        if ($story->mediaFile !== null) {
            $mediaUrl = $urls->forSend($story->mediaFile);

            // Storage purged (cleanup race), a legacy local-disk row, or
            // storage disabled: no URL WAHA could fetch. Fail the story
            // with an explicit cause instead of sending WAHA dead bytes
            // and failing with a confusing fetch error. Disabled storage
            // is fixable from Settings, so say so instead of describing a
            // purge the admin cannot undo here.
            if ($mediaUrl === null) {
                $disabled = $storage->configured() === false && $story->mediaFile->disk === 's3';

                $message = $disabled
                    ? 'S3 storage is disabled or not configured (Settings → Storage); re-enable it, then retry.'
                    : 'The media file is no longer available (purged by cleanup or stored on the removed local disk); upload new media and edit the story.';

                $marked = Story::query()
                    ->whereKey($story->id)
                    ->where('status', StoryStatus::Pending->value)
                    ->update([
                        'status' => StoryStatus::Failed->value,
                        'next_attempt_at' => null,
                        'last_error' => $message,
                        'last_error_code' => 'media_unavailable',
                    ]);

                // Log only on the transition: a duplicate worker on an
                // already-failed row must not append another entry.
                if ($marked > 0) {
                    $story->logs()->create([
                        'event' => 'failed_held',
                        'message' => $message,
                    ]);
                }

                return;
            }
        }

        // Busy session: release the job back without counting an attempt.
        $lease = $gate->acquire($snapshot);
        if ($lease === null) {
            if ($this->job !== null) {
                $this->release(5);
            }

            return;
        }

        try {
            // Re-check eligibility after the lock is held.
            $story->refresh();

            if (! $claim->isEligible($story, $snapshot)) {
                return;
            }

            $token = $claim->handle($this->storyId, $snapshot);

            if ($token === null) {
                return;
            }

            try {
                $result = $client->sendStory($snapshot, $story, $mediaUrl);
                $complete->handle($this->storyId, $token, $result);
            } catch (WahaRequestException $exception) {
                $complete->handle($this->storyId, $token, $exception);
            }
        } finally {
            $lease->release();
        }
    }
}
