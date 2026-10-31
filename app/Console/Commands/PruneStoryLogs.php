<?php

namespace App\Console\Commands;

use App\Models\StoryLog;
use Illuminate\Console\Command;

/**
 * Weekly retention (spec G2): keep the newest 10,000 story log rows, pruned
 * in stable (created_at, id) order. Attempt evidence backing an unknown/sent
 * story lives in send_attempts, which is never pruned here.
 */
class PruneStoryLogs extends Command
{
    protected $signature = 'waha:prune-story-logs {--keep=10000 : Number of newest log rows to keep}';

    protected $description = 'Prune story logs to the newest N rows';

    public function handle(): int
    {
        $keep = max(100, (int) $this->option('keep'));

        // First row beyond the newest $keep rows in stable (created_at, id)
        // order; it and everything older gets pruned.
        $cutoff = StoryLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->first();

        if ($cutoff === null) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        $removed = StoryLog::query()
            ->where('created_at', '<', $cutoff->created_at)
            ->orWhere(function ($query) use ($cutoff): void {
                $query->where('created_at', $cutoff->created_at)
                    ->where('id', '<=', $cutoff->id);
            })
            ->delete();

        $this->info("Pruned {$removed} story log row(s).");

        return self::SUCCESS;
    }
}
