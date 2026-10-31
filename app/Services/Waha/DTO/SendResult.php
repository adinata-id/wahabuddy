<?php

namespace App\Services\Waha\DTO;

/**
 * Result of a send request accepted by WAHA.
 *
 * Invariant (spec M): at least one of messageId (top-level `id`) or keyId
 * (`key.id`) must be present — success with only one of them is still valid.
 * WahaClient enforces this by throwing an unknown-outcome exception instead
 * of constructing an empty result.
 */
final readonly class SendResult
{
    public function __construct(
        public readonly ?string $messageId,
        public readonly ?string $keyId,
    ) {}

    public function hasProof(): bool
    {
        return $this->messageId !== null || $this->keyId !== null;
    }
}
