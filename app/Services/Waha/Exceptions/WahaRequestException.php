<?php

namespace App\Services\Waha\Exceptions;

/**
 * Sanitized failure of a WAHA request.
 *
 * `outcome` follows the send-safety contract (spec D/G):
 * - 'rejected': the target provably did NOT accept the request (4xx family
 *   excluding ambiguous cases) — safe to retry only when `retryable` is true.
 * - 'unknown': acceptance cannot be denied (timeout, connection loss, 5xx
 *   after the request may have been processed, unrecognized payloads).
 *   Never auto-retried.
 *
 * Messages are static or redacted — never raw response bodies with secrets.
 */
final class WahaRequestException extends \RuntimeException
{
    /**
     * @param  'rejected'|'unknown'  $outcome
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly ?int $httpStatus,
        public readonly string $safeMessage,
        public readonly string $outcome,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($safeMessage);
    }

    public function isUnknown(): bool
    {
        return $this->outcome === 'unknown';
    }
}
