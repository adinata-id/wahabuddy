<?php

namespace App\Actions\Stories;

use App\Enums\SendAttemptOutcome;
use App\Enums\StoryStatus;
use App\Models\SendAttempt;
use App\Models\Story;
use App\Services\Waha\ConnectionRepository;
use App\Services\Waha\DTO\SendResult;
use App\Services\Waha\Exceptions\WahaRequestException;

/**
 * Conditional completion of a claimed story (spec G2/M).
 *
 * Every write is guarded by `attempt_token = ?`: a stale worker resuming
 * after a newer attempt started can never overwrite the newer outcome.
 */
final class CompleteStory
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ConnectionRepository $connections,
    ) {}

    /**
     * Persist the send outcome for the worker holding $attemptToken.
     * Returns false when the token no longer owns the row.
     */
    public function handle(int $storyId, string $attemptToken, SendResult|WahaRequestException $outcome): bool
    {
        $story = Story::find($storyId);

        if ($story === null || $story->attempt_token !== $attemptToken) {
            return false;
        }

        if ($outcome instanceof SendResult) {
            return $this->completeWithResult($story, $attemptToken, $outcome);
        }

        return $this->completeWithException($story, $attemptToken, $outcome);
    }

    private function completeWithResult(Story $story, string $token, SendResult $result): bool
    {
        $updated = Story::query()
            ->whereKey($story->id)
            ->where('attempt_token', $token)
            ->where('status', StoryStatus::Sending->value)
            ->update([
                'status' => StoryStatus::Sent->value,
                'waha_message_id' => $result->messageId,
                'waha_key_id' => $result->keyId,
                'sent_at' => now(),
                'next_attempt_at' => null,
                'attempt_token' => null,
                'last_error' => null,
                'last_error_code' => null,
            ]);

        if ($updated === 0) {
            return false;
        }

        $this->finishAttempt($token, SendAttemptOutcome::Sent, message_id: $result->messageId, key_id: $result->keyId);
        $this->log($story, 'sent', 'WAHA accepted the story send.', ['message_id' => $result->messageId, 'key_id' => $result->keyId]);

        return true;
    }

    private function completeWithException(Story $story, string $token, WahaRequestException $exception): bool
    {
        $story = $story->refresh();

        if ($story->attempt_token !== $token) {
            return false;
        }

        $attempts = $story->attempts;
        $connection = $this->connections->snapshot();

        $retryable = $exception->retryable && $attempts < self::MAX_ATTEMPTS;

        $fields = [
            'last_error_code' => $exception->errorCode,
            'last_error' => mb_substr($exception->safeMessage, 0, 500),
            'attempt_token' => null,
            'sending_started_at' => null,
        ];

        $event = 'failed';

        if ($exception->outcome === 'unknown') {
            // Ambiguous acceptance: never auto-retry (spec G2).
            $fields['status'] = StoryStatus::Unknown->value;
            $fields['next_attempt_at'] = null;
            $event = 'unknown';
        } elseif ($retryable) {
            $fields['status'] = StoryStatus::Failed->value;
            $fields['next_attempt_at'] = $this->nextAttemptAt($story, $exception);
            $event = 'failed_retryable';
        } elseif ($attempts >= self::MAX_ATTEMPTS) {
            $fields['status'] = StoryStatus::FailedMax->value;
            $fields['next_attempt_at'] = null;
            $event = 'failed_max';
        } else {
            // Deterministic rejection: held until a human fixes the cause.
            $fields['status'] = StoryStatus::Failed->value;
            $fields['next_attempt_at'] = null;
            $event = 'failed_held';
        }

        $updated = Story::query()
            ->whereKey($story->id)
            ->where('attempt_token', $token)
            ->where('status', StoryStatus::Sending->value)
            ->update($fields);

        if ($updated === 0) {
            return false;
        }

        $this->finishAttempt(
            $token,
            $exception->outcome === 'unknown' ? SendAttemptOutcome::Unknown : SendAttemptOutcome::Rejected,
            error_code: $exception->errorCode,
            error: mb_substr($exception->safeMessage, 0, 500),
        );

        $this->log($story, $event, $exception->safeMessage, [
            'error_code' => $exception->errorCode,
            'http_status' => $exception->httpStatus,
            'retry_after' => $exception->retryAfterSeconds,
            'connection_revision' => $connection->revision,
        ]);

        return true;
    }

    /**
     * Backoff schedule: explicit Retry-After wins (429), otherwise the safe
     * transient ladder 60s then 300s (spec G2).
     */
    private function nextAttemptAt(Story $story, WahaRequestException $exception): \DateTimeInterface
    {
        if ($exception->retryAfterSeconds !== null && $exception->retryAfterSeconds > 0) {
            return now()->addSeconds($exception->retryAfterSeconds);
        }

        $ladder = config('wahabuddy.stories.safe_backoff_seconds', [60, 300]);
        $delay = $ladder[min($story->attempts - 1, count($ladder) - 1)] ?? 300;

        return now()->addSeconds((int) $delay);
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
