<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Periodic worker heartbeat so the dashboard can tell whether a queue worker
 * is actually consuming jobs (spec G2).
 */
class RecordWorkerHeartbeatJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CACHE_KEY = 'waha.worker.heartbeat';

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, now()->toAtomString(), now()->addDay());
    }
}
