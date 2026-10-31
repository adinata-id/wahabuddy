<?php

namespace App\Actions\Stories;

use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Enums\StoryStatus;
use App\Models\SendAttempt;
use App\Models\Story;

/**
 * Admin resolution of an unknown story outcome (spec G1/M).
 *
 * `mark_sent` requires a stored proof id or an explicit admin confirmation
 * note — a WAHA id is never fabricated. `retry` acknowledges the duplication
 * risk and starts a fresh attempt cycle. Both decisions are audited.
 */
final class ResolveStory
{
    /**
     * @param  'mark_sent'|'retry'  $decision
     *
     * @throws \InvalidArgumentException when the decision cannot be applied
     */
    public function handle(Story $story, string $decision, string $note): void
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A note is required for every unknown resolution.');
        }

        if ($story->status !== StoryStatus::Unknown) {
            throw new \InvalidArgumentException('Only stories in unknown state can be resolved.');
        }

        if ($decision === 'mark_sent') {
            $this->markSent($story, $note);

            return;
        }

        // The parameter type admits only mark_sent|retry.
        $this->retry($story, $note);
    }

    private function markSent(Story $story, string $note): void
    {
        $proof = SendAttempt::query()
            ->where('subject_type', SendAttemptSubject::Story)
            ->where('subject_id', $story->id)
            ->where('outcome', SendAttemptOutcome::Sent)
            ->orderByDesc('finished_at')
            ->first();

        $updated = Story::query()
            ->whereKey($story->id)
            ->where('status', StoryStatus::Unknown->value)
            ->update([
                'status' => StoryStatus::Sent->value,
                'sent_at' => now(),
                'waha_message_id' => $proof->message_id ?? $story->waha_message_id,
                'waha_key_id' => $proof->key_id ?? $story->waha_key_id,
                'attempt_token' => null,
                'sending_started_at' => null,
            ]);

        if ($updated === 0) {
            return;
        }

        $story->refresh();
        $story->logs()->create([
            'event' => 'resolved_mark_sent',
            'message' => 'Admin marked the unknown story as sent.',
            'context' => [
                'note' => mb_substr($note, 0, 500),
                'from_stored_proof' => $proof !== null,
                'message_id' => $proof?->message_id,
            ],
        ]);
    }

    private function retry(Story $story, string $note): void
    {
        $updated = Story::query()
            ->whereKey($story->id)
            ->where('status', StoryStatus::Unknown->value)
            ->update([
                'status' => StoryStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'attempt_token' => null,
                'sending_started_at' => null,
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($updated === 0) {
            return;
        }

        $story->refresh();
        $story->logs()->create([
            'event' => 'resolved_retry',
            'message' => 'Admin acknowledged the duplication risk and opened a new retry cycle.',
            'context' => ['note' => mb_substr($note, 0, 500)],
        ]);
    }
}
