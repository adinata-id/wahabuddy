<?php

namespace App\Services\Sending;

use Illuminate\Contracts\Cache\Lock;

/**
 * Handle for a session send slot obtained from SessionSendGate. Releasing is
 * always safe: a lease whose lock already expired is a no-op (spec M).
 */
final class SendLease
{
    public function __construct(
        private readonly Lock $lock,
        public readonly string $key,
        public readonly int $leaseSeconds,
    ) {}

    public function release(): void
    {
        try {
            $this->lock->release();
        } catch (\Throwable) {
            // An expired or already-released lock is fine: the lease simply
            // lapsed. Never let cleanup mask the send outcome.
        }
    }
}
