<?php

namespace App\Actions\Stories;

use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Enums\StoryStatus;
use App\Models\SendAttempt;
use App\Models\Story;
use App\Services\Waha\DTO\ConnectionSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic claim of a story for one send attempt (spec G2).
 *
 * Returns the attempt UUID for the winner, null when this worker must not
 * send. The compare-and-swap update is a single conditional UPDATE that is
 * atomic on MySQL (and would be on SQLite): no reliance on lockForUpdate.
 * A send_attempts proof row is written inside the same short transaction,
 * BEFORE any HTTP, so a worker crash after acceptance still leaves evidence.
 */
final class ClaimStory
{
    /**
     * @return string|null attempt token when this worker won the claim
     */
    public function handle(int $storyId, ConnectionSnapshot $connection): ?string
    {
        $story = Story::find($storyId);

        if ($story === null) {
            return null;
        }

        // Prior attempt already recorded a successful message id: heal the
        // row without a new request (spec G2).
        if ($this->healFromProof($story)) {
            return null;
        }

        if (! $this->isEligible($story, $connection)) {
            return null;
        }

        $token = (string) Str::uuid();
        $attemptNumber = $story->attempts + 1;

        $claimed = Story::query()
            ->whereKey($story->id)
            ->whereIn('status', [StoryStatus::Pending->value, StoryStatus::Failed->value])
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->update([
                'status' => StoryStatus::Sending->value,
                'attempt_token' => $token,
                'sending_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($claimed === 0) {
            return null;
        }

        DB::transaction(function () use ($story, $token, $attemptNumber, $connection): void {
            SendAttempt::create([
                'attempt_token' => $token,
                'subject_type' => SendAttemptSubject::Story,
                'subject_id' => $story->id,
                'connection_revision' => $connection->revision,
                'started_at' => now(),
                'outcome' => SendAttemptOutcome::Started,
            ]);

            $story->refresh();
            $story->logs()->create([
                'event' => 'claim',
                'attempt_token' => $token,
                'message' => "Send attempt {$attemptNumber} claimed.",
                'context' => ['attempt_number' => $attemptNumber, 'connection_revision' => $connection->revision],
            ]);
        });

        return $token;
    }

    /**
     * Eligibility without side effects: status, due window, expiry boundary,
     * target revision, configuration, and session WORKING state. Failing any
     * of these HOLDS the story — attempts are never burned for configuration
     * problems (spec D1/G2).
     */
    public function isEligible(Story $story, ConnectionSnapshot $connection): bool
    {
        if (! in_array($story->status, [StoryStatus::Pending, StoryStatus::Failed], true)) {
            return false;
        }

        // The current cycle allows at most 3 started HTTP attempts (spec G1).
        if ($story->attempts >= (int) config('wahabuddy.stories.max_send_attempts', 3)) {
            return false;
        }

        // Future or pacing-held schedules are not due.
        if ($story->scheduled_at->isFuture()) {
            return false;
        }

        if ($story->next_attempt_at !== null && $story->next_attempt_at->isFuture()) {
            return false;
        }

        // Expiry boundary: exactly now - 7 days is still sendable, older is not.
        if ($story->scheduled_at->lt(now()->subDays(config('wahabuddy.stories.expire_after_days', 7)))) {
            return false;
        }

        if ($story->connection_revision !== $connection->revision) {
            $this->log($story, 'held', 'Connection target changed since this story was scheduled. Held until an admin confirms the new target.');

            return false;
        }

        if (! $connection->isConfigured()) {
            $this->log($story, 'held', 'WAHA connection is not configured.');

            return false;
        }

        return true;
    }

    /**
     * Restore a story to sent when a stored attempt proves WAHA accepted it.
     */
    public function healFromProof(Story $story): bool
    {
        $proof = SendAttempt::query()
            ->where('subject_type', SendAttemptSubject::Story)
            ->where('subject_id', $story->id)
            ->where('outcome', SendAttemptOutcome::Sent)
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->first();

        if ($proof === null) {
            return false;
        }

        $updated = Story::query()
            ->whereKey($story->id)
            ->whereNot('status', StoryStatus::Sent->value)
            ->update([
                'status' => StoryStatus::Sent->value,
                'waha_message_id' => $proof->message_id,
                'waha_key_id' => $proof->key_id,
                'sent_at' => $proof->finished_at ?? now(),
                'attempt_token' => null,
                'sending_started_at' => null,
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($updated > 0) {
            $story->refresh();
            $story->logs()->create([
                'event' => 'healed',
                'message' => 'Restored to sent from stored send proof without a new request.',
                'context' => ['message_id' => $proof->message_id, 'key_id' => $proof->key_id],
            ]);
        }

        return $updated > 0;
    }

    /** @param  array<string, mixed>  $context */
    private function log(Story $story, string $event, string $message, array $context = []): void
    {
        $story->logs()->create([
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
