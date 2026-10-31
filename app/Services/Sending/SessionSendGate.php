<?php

namespace App\Services\Sending;

use App\Services\Waha\DTO\ConnectionSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes sends per WAHA session (stories and broadcasts share the same
 * gate) and owns the session pacing timestamp (spec G2/I).
 *
 * Locks use the cache lock store (database cache/locks by default) with a
 * 300s lease — longer than the worker timeout — so a crashed worker can never
 * wedge the session forever. The gate performs no HTTP itself.
 */
final class SessionSendGate
{
    public const LEASE_SECONDS = 300;

    public const PACING_KEY = 'waha.session.pacing';

    /**
     * Acquire the send slot without waiting. Null means the session is busy:
     * the caller must release its job back to the queue WITHOUT counting a
     * send attempt.
     */
    public function acquire(ConnectionSnapshot $connection): ?SendLease
    {
        $lock = Cache::lock($this->lockKey($connection), self::LEASE_SECONDS);

        return $lock->get() ? new SendLease($lock, $this->lockKey($connection), self::LEASE_SECONDS) : null;
    }

    /**
     * Wait briefly for the send slot (used by commands that must proceed).
     *
     * @throws LockTimeoutException
     */
    public function block(ConnectionSnapshot $connection, int $seconds): SendLease
    {
        $lock = Cache::lock($this->lockKey($connection), self::LEASE_SECONDS)->block($seconds);

        return new SendLease($lock, $this->lockKey($connection), self::LEASE_SECONDS);
    }

    /**
     * Earliest instant the session may send again, or null when no pacing
     * wait applies. Broadcasts set this after each completion; stories have
     * priority and simply wait for it too when set (spec I).
     */
    public function nextAllowedAt(ConnectionSnapshot $connection): ?CarbonInterface
    {
        $timestamp = Cache::get($this->pacingKey($connection));

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(\DateTimeInterface::ATOM, $timestamp)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Record that a send finished now, pushing the session-wide earliest-next
     * send to `$delaySeconds` from completion. Called while holding the gate.
     */
    public function recordPacing(ConnectionSnapshot $connection, int $delaySeconds): void
    {
        Cache::put(
            $this->pacingKey($connection),
            now()->utc()->addSeconds($delaySeconds)->toAtomString(),
            now()->addDay(),
        );
    }

    public function lockKey(ConnectionSnapshot $connection): string
    {
        return 'waha.send.'.sha1(($connection->baseUrl ?? '').'|'.$connection->session);
    }

    private function pacingKey(ConnectionSnapshot $connection): string
    {
        return self::PACING_KEY.'.'.sha1(($connection->baseUrl ?? '').'|'.$connection->session);
    }
}
