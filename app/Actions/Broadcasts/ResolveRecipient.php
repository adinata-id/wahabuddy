<?php

namespace App\Actions\Broadcasts;

use App\Enums\RecipientStatus;
use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Models\BroadcastRecipient;
use App\Models\SendAttempt;

/**
 * Admin resolution of an unknown recipient outcome (spec I/M). Same rules as
 * stories: note mandatory, proof or explicit confirmation, never fabricated
 * ids, retry acknowledges duplication risk. The campaign stays paused —
 * resuming is a separate explicit action.
 */
final class ResolveRecipient
{
    /**
     * @param  'mark_sent'|'retry'  $decision
     */
    public function handle(BroadcastRecipient $recipient, string $decision, string $note): void
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A note is required for every unknown resolution.');
        }

        if ($recipient->status !== RecipientStatus::Unknown) {
            throw new \InvalidArgumentException('Only recipients in unknown state can be resolved.');
        }

        if ($decision === 'mark_sent') {
            $this->markSent($recipient, $note);

            return;
        }

        $this->retry($recipient, $note);
    }

    private function markSent(BroadcastRecipient $recipient, string $note): void
    {
        $proof = SendAttempt::query()
            ->where('subject_type', SendAttemptSubject::Recipient)
            ->where('subject_id', $recipient->id)
            ->where('outcome', SendAttemptOutcome::Sent)
            ->orderByDesc('finished_at')
            ->first();

        $updated = BroadcastRecipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Unknown->value)
            ->update([
                'status' => RecipientStatus::Sent->value,
                'sent_at' => now(),
                'waha_message_id' => $proof->message_id ?? $recipient->waha_message_id,
                'waha_key_id' => $proof->key_id ?? $recipient->waha_key_id,
                'attempt_token' => null,
            ]);

        if ($updated === 0) {
            return;
        }

        $this->log($recipient, 'resolved_mark_sent', 'Admin marked the unknown recipient as sent.', [
            'note' => mb_substr($note, 0, 500),
            'from_stored_proof' => $proof !== null,
        ]);
    }

    private function retry(BroadcastRecipient $recipient, string $note): void
    {
        $updated = BroadcastRecipient::query()
            ->whereKey($recipient->id)
            ->where('status', RecipientStatus::Unknown->value)
            ->update([
                'status' => RecipientStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'attempt_token' => null,
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($updated === 0) {
            return;
        }

        $this->log($recipient, 'resolved_retry', 'Admin acknowledged the duplication risk and opened a new retry cycle.', [
            'note' => mb_substr($note, 0, 500),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    private function log(BroadcastRecipient $recipient, string $event, string $message, array $context = []): void
    {
        $recipient->broadcast->logs()->create([
            'recipient_id' => $recipient->id,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
