<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\SendAttempt;
use App\Services\Waha\DTO\ConnectionSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic claim of one recipient for one send attempt (spec I/M).
 *
 * Mirrors the story claim: a single conditional UPDATE wins exactly one
 * worker; the send_attempts proof row is written in the same short
 * transaction before any HTTP. Campaign-level gates (paused, pacing,
 * revision mismatch) hold without burning attempts.
 */
final class ClaimRecipient
{
    /**
     * @return array{recipient: BroadcastRecipient, token: string}|null
     */
    public function handle(int $broadcastId, ConnectionSnapshot $connection): ?array
    {
        $broadcast = Broadcast::find($broadcastId);

        if ($broadcast === null || ! $this->campaignRunnable($broadcast)) {
            return null;
        }

        if ($broadcast->connection_revision !== $connection->revision) {
            $this->logCampaign($broadcast, 'held', 'Connection target changed since this campaign was queued; held until confirmed.');

            return null;
        }

        if ($broadcast->next_send_at !== null && $broadcast->next_send_at->isFuture()) {
            return null; // pacing: not due yet, jobs will come back later
        }

        $token = (string) Str::uuid();

        // Candidate selection is lock-free; the conditional UPDATE below is
        // the atomic arbiter when workers race on the same candidate.
        $recipientId = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', RecipientStatus::Pending->value)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->value('id');

        if ($recipientId === null) {
            return null;
        }

        $recipientId = (int) $recipientId;

        $claimed = BroadcastRecipient::query()
            ->whereKey($recipientId)
            ->where('status', RecipientStatus::Pending->value)
            ->update([
                'status' => RecipientStatus::Sending->value,
                'attempt_token' => $token,
                'sending_started_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($claimed === 0) {
            return null;
        }

        $recipient = BroadcastRecipient::find($recipientId);

        DB::transaction(function () use ($recipient, $token, $connection): void {
            SendAttempt::create([
                'attempt_token' => $token,
                'subject_type' => SendAttemptSubject::Recipient,
                'subject_id' => $recipient->id,
                'connection_revision' => $connection->revision,
                'started_at' => now(),
                'outcome' => SendAttemptOutcome::Started,
            ]);

            $recipient->broadcast->logs()->create([
                'recipient_id' => $recipient->id,
                'event' => 'claim',
                'message' => "Send attempt {$recipient->attempts} claimed for {$recipient->chat_id}.",
            ]);
        });

        return ['recipient' => $recipient, 'token' => $token];
    }

    public function campaignRunnable(Broadcast $broadcast): bool
    {
        return in_array($broadcast->status, [BroadcastStatus::Queued, BroadcastStatus::Running], true);
    }

    private function logCampaign(Broadcast $broadcast, string $event, string $message): void
    {
        $broadcast->logs()->create([
            'event' => $event,
            'message' => $message,
        ]);
    }
}
