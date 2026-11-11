<?php

namespace App\Console\Commands;

use App\Enums\SchedulerRunStatus;
use App\Enums\StoryStatus;
use App\Jobs\SendStoryJob;
use App\Models\SchedulerRun;
use App\Models\Story;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Console\Command;

/**
 * Fast dispatcher for due stories (spec G2): runs every minute under
 * withoutOverlapping(), marks expiries, holds work whose target revision
 * changed, and queues one job per due row. It performs no HTTP itself.
 */
class DispatchDueStories extends Command
{
    protected $signature = 'waha:send-stories';

    protected $description = 'Dispatch due story sends (no HTTP in this command)';

    public function handle(ConnectionRepository $connections): int
    {
        $run = SchedulerRun::create([
            'started_at' => now(),
            'status' => SchedulerRunStatus::Running,
        ]);

        $processed = 0;
        $errors = 0;
        $lastError = null;

        try {
            $expireBefore = now()->subDays((int) config('wahabuddy.stories.expire_after_days', 7));

            // 1) Expire pending/failed stories older than the 7-day boundary.
            //    The boundary itself (== now - 7d) is still sendable.
            $expired = Story::query()
                ->whereIn('status', [StoryStatus::Pending, StoryStatus::Failed, StoryStatus::FailedMax])
                ->where('scheduled_at', '<', $expireBefore)
                ->get();

            foreach ($expired as $story) {
                $story->logs()->create([
                    'event' => 'expired',
                    'message' => 'Schedule is older than the 7-day send window.',
                ]);
                $story->update([
                    'status' => StoryStatus::Expired,
                    'next_attempt_at' => null,
                    'attempt_token' => null,
                ]);
                $processed++;
            }

            // 2) Queue one job per due row (chunked; revision holds applied).
            // Held failures (no retry scheduled) stay out of the due set —
            // they wait for an explicit admin retry (Story::scopeDispatchable).
            $revision = $connections->current()->revision;

            Story::query()
                ->dispatchable()
                ->orderBy('scheduled_at')
                ->chunkById(100, function ($chunk) use (&$processed, &$errors, &$lastError): void {
                    foreach ($chunk as $story) {
                        try {
                            SendStoryJob::dispatch($story->id);
                            $processed++;
                        } catch (\Throwable $exception) {
                            $errors++;
                            $lastError = mb_substr($exception->getMessage(), 0, 500);
                        }
                    }
                });

            $run->update([
                'finished_at' => now(),
                'status' => SchedulerRunStatus::Succeeded,
                'processed_count' => $processed,
                'error_count' => $errors,
                'last_error' => $lastError,
            ]);

            if ($processed > 0 && $this->getOutput()->isVerbose()) {
                $this->info("Dispatched/expired {$processed} story row(s).");
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $run->update([
                'finished_at' => now(),
                'status' => SchedulerRunStatus::Failed,
                'error_count' => $errors + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ]);

            throw $exception;
        }
    }
}
