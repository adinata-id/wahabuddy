<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Enums\SendAttemptOutcome;
use App\Models\AppSetting;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\SendAttempt;
use App\Services\Broadcasts\PacingClock;
use App\Services\Sending\SessionSendGate;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\DTO\SendResult;
use App\Services\Waha\Exceptions\WahaRequestException;
use Illuminate\Support\Facades\DB;

/**
 * Token-guarded completion for one broadcast recipient (spec I/M).
 *
 * Beyond the story semantics this also owns the campaign mechanics: pacing
 * (next_send_at = completion + random 8..20s), the shared session pacing
 * stamp, batch pauses after every 50 HTTP attempts, and pausing the campaign
 * on any unknown outcome.
 */
final class CompleteRecipient
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PacingClock $clock,
    ) {}

    public function handle(int $recipientId, string $attemptToken, SendResult|WahaRequestException $outcome): bool
    {
        return DB::transaction(function () use ($recipientId, $attemptToken, $outcome): bool {
            $recipient = BroadcastRecipient::lockForUpdate()->find($recipientId);

            if ($recipient === null || $recipient->attempt_token !== $attemptToken) {
                return false; // stale worker or already-finished row
            }

            $broadcast = $recipient->broadcast()->lockForUpdate()->firstOrFail();

            if ($outcome instanceof SendResult) {
                $this->completeSuccess($recipient, $broadcast, $attemptToken, $outcome);

                return true;
            }

            $this->completeFailure($recipient, $broadcast, $attemptToken, $outcome);

            return true;
        });
    }

    private function completeSuccess(BroadcastRecipient $recipient, Broadcast $broadcast, string $token, SendResult $result): void
    {
        $recipient->forceFill([
            'status' => RecipientStatus::Sent,
            'waha_message_id' => $result->messageId,
            'waha_key_id' => $result->keyId,
            'sent_at' => now(),
            'next_attempt_at' => null,
            'attempt_token' => null,
            'last_error' => null,
            'last_error_code' => null,
        ])->save();

        $this->finishAttempt($token, SendAttemptOutcome::Sent, message_id: $result->messageId, key_id: $result->keyId);

        $broadcast->logs()->create([
            'recipient_id' => $recipient->id,
            'event' => 'sent',
            'message' => "Message accepted for {$recipient->chat_id}.",
            'context' => ['message_id' => $result->messageId],
        ]);

        $this->afterAttempt($broadcast);
    }

    private function completeFailure(BroadcastRecipient $recipient, Broadcast $broadcast, string $token, WahaRequestException $exception): void
    {
        $attempts = $recipient->attempts;
        $retryable = $exception->retryable && $attempts < self::MAX_ATTEMPTS;

        $fields = [
            'last_error_code' => $exception->errorCode,
            'last_error' => mb_substr($exception->safeMessage, 0, 500),
            'attempt_token' => null,
        ];

        $event = 'failed';
        $pauseCampaign = false;
        $pauseEvent = null;
        $pauseMessage = null;

        if ($exception->outcome === 'unknown') {
            // Ambiguous acceptance never auto-retries and pauses the campaign
            // until an admin resolves it (spec I).
            $fields['status'] = RecipientStatus::Unknown;
            $fields['next_attempt_at'] = null;
            $event = 'unknown';
            $pauseCampaign = true;
            $pauseEvent = 'paused_unknown';
            $pauseMessage = 'Campaign paused: unknown outcome requires admin resolution before continuing.';
        } elseif ($retryable) {
            $fields['status'] = RecipientStatus::Failed;
            $fields['next_attempt_at'] = $this->nextAttemptAt($recipient, $exception);
            $event = 'failed_retryable';
        } elseif ($attempts >= self::MAX_ATTEMPTS) {
            $fields['status'] = RecipientStatus::FailedMax;
            $fields['next_attempt_at'] = null;
            $event = 'failed_max';
        } else {
            $fields['status'] = RecipientStatus::Failed;
            $fields['next_attempt_at'] = null; // deterministic: held for admin
            $event = 'failed_held';

            // Unsendable media is campaign-wide and deterministic: pause so
            // the remaining recipients survive until the admin restores the
            // media, instead of pacing through all of them as failures.
            if ($exception->errorCode === 'media_unavailable') {
                $pauseCampaign = true;
                $pauseEvent = 'paused_media_unavailable';
                $pauseMessage = 'Campaign paused: the media file is no longer sendable. Upload new media, edit the broadcast, then resume.';
            }
        }

        $recipient->forceFill($fields)->save();

        $this->finishAttempt(
            $token,
            $exception->outcome === 'unknown' ? SendAttemptOutcome::Unknown : SendAttemptOutcome::Rejected,
            error_code: $exception->errorCode,
            error: mb_substr($exception->safeMessage, 0, 500),
        );

        $broadcast->logs()->create([
            'recipient_id' => $recipient->id,
            'event' => $event,
            'message' => $exception->safeMessage,
            'context' => ['chat_id' => $recipient->chat_id, 'error_code' => $exception->errorCode],
        ]);

        $this->afterAttempt($broadcast);

        if ($pauseCampaign && in_array($broadcast->status, [BroadcastStatus::Queued, BroadcastStatus::Running], true)) {
            $broadcast->forceFill(['status' => BroadcastStatus::Paused])->save();
            $broadcast->logs()->create([
                'recipient_id' => $recipient->id,
                'event' => $pauseEvent,
                'message' => $pauseMessage,
            ]);
        }
    }

    /**
     * Pacing + batch bookkeeping runs after EVERY HTTP attempt (success or
     * failure) so the attempt budget matches spec I.
     */
    private function afterAttempt(Broadcast $broadcast): void
    {
        $delay = $this->clock->randomDelaySeconds();
        $settings = AppSetting::singleton();

        $broadcast->forceFill([
            'status' => $broadcast->status === BroadcastStatus::Queued ? BroadcastStatus::Running : $broadcast->status,
            'batch_count' => $broadcast->batch_count + 1,
            'next_send_at' => now()->addSeconds($delay),
        ])->save();

        // Session-wide pacing stamp so other campaigns on the same session
        // also wait (written while holding the session gate).
        app(SessionSendGate::class)->recordPacing(
            app(ConnectionRepository::class)->snapshot(),
            $delay,
        );

        if ($broadcast->batch_count >= $settings->broadcast_batch_size) {
            $broadcast->forceFill([
                'batch_count' => 0,
                'next_send_at' => now()->addSeconds($settings->broadcast_batch_pause),
            ])->save();

            $broadcast->logs()->create([
                'event' => 'batch_pause',
                'message' => "Pausing after {$settings->broadcast_batch_size} HTTP attempts for at least {$settings->broadcast_batch_pause}s.",
            ]);
        }
    }

    private function nextAttemptAt(BroadcastRecipient $recipient, WahaRequestException $exception): \DateTimeInterface
    {
        if ($exception->retryAfterSeconds !== null && $exception->retryAfterSeconds > 0) {
            return now()->addSeconds($exception->retryAfterSeconds);
        }

        $ladder = [60, 300];

        return now()->addSeconds((int) ($ladder[min($recipient->attempts - 1, 1)] ?? 300));
    }

    private function finishAttempt(
        string $token,
        SendAttemptOutcome $outcome,
        ?string $message_id = null,
        ?string $key_id = null,
        ?string $error_code = null,
        ?string $error = null,
    ): void {
        SendAttempt::query()
            ->where('attempt_token', $token)
            ->update([
                'outcome' => $outcome,
                'finished_at' => now(),
                'message_id' => $message_id,
                'key_id' => $key_id,
                'error_code' => $error_code,
                'error' => $error,
            ]);
    }
}
