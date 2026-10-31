<?php

namespace App\Console\Commands;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Jobs\ProcessBroadcastJob;
use App\Models\Broadcast;
use Illuminate\Console\Command;

/**
 * Sweeper for campaigns left without a follow-up job: a worker crashed after
 * persisting an outcome but before dispatching the next execution (spec I).
 * Claim protection makes redundant dispatches harmless no-ops.
 */
class RecoverBroadcasts extends Command
{
    protected $signature = 'waha:recover-broadcasts';

    protected $description = 'Re-queue runnable campaigns that lost their follow-up job';

    public function handle(): int
    {
        $requeued = 0;

        Broadcast::query()
            ->whereIn('status', [BroadcastStatus::Queued, BroadcastStatus::Running])
            ->where(function ($query): void {
                $query->whereNull('next_send_at')->orWhere('next_send_at', '<=', now()->addMinute());
            })
            ->whereHas('recipients', function ($query): void {
                $query->where('status', RecipientStatus::Pending->value);
            })
            ->chunkById(50, function ($campaigns) use (&$requeued): void {
                foreach ($campaigns as $broadcast) {
                    ProcessBroadcastJob::dispatch($broadcast->id);
                    $requeued++;
                }
            });

        if ($requeued > 0) {
            $this->info("Re-queued {$requeued} campaign(s).");
        }

        return self::SUCCESS;
    }
}
