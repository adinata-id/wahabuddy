<?php

namespace App\Services\Waha\DTO;

/**
 * Internal, fully-resolved view of the WAHA connection target.
 *
 * Contains plaintext secrets and is therefore NEVER serialized to Inertia
 * responses or logs — it exists to hand jobs and the HTTP client a single
 * consistent target (spec section M: `ConnectionRepository::current()`).
 */
final readonly class ConnectionSnapshot
{
    public function __construct(
        public readonly ?string $baseUrl,
        public readonly ?string $apiKey,
        public readonly ?string $basicUsername,
        public readonly ?string $basicPassword,
        public readonly string $session,
        public readonly int $revision,
        public readonly array $capabilities,
    ) {}

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null && $this->baseUrl !== '';
    }

    /**
     * Stable hash of the effective send target (URL, session, and credentials).
     * Used to bind stored test results to the configuration they belong to.
     */
    public function configHash(): string
    {
        return hash('sha256', implode('|', [
            $this->baseUrl ?? '',
            $this->session,
            $this->apiKey ?? '',
            $this->basicUsername ?? '',
            $this->basicPassword ?? '',
        ]));
    }
}
