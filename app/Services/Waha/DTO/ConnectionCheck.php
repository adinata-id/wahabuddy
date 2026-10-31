<?php

namespace App\Services\Waha\DTO;

use DateTimeImmutable;

/**
 * Result of a read-only connection test against WAHA. All fields are safe to
 * send to the browser: no secrets, no raw remote payloads.
 */
final readonly class ConnectionCheck
{
    /**
     * @param  array<string, string>|null  $me
     */
    public function __construct(
        public readonly bool $working,
        public readonly ?string $sessionStatus,
        public readonly ?string $sessionName,
        public readonly ?array $me,
        public readonly ?string $engine,
        public readonly ?string $wahaVersion,
        public readonly ?string $errorCode,
        public readonly ?string $error,
        public readonly DateTimeImmutable $testedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'working' => $this->working,
            'sessionStatus' => $this->sessionStatus,
            'sessionName' => $this->sessionName,
            'me' => $this->me,
            'engine' => $this->engine,
            'wahaVersion' => $this->wahaVersion,
            'errorCode' => $this->errorCode,
            'error' => $this->error,
            'testedAt' => $this->testedAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
