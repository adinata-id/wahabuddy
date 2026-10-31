<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\MediaCleanupRun;
use App\Services\Media\SentMediaCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Purges stored media of stories/broadcasts that have all reached a
 * terminal state (sent by default) longer than the retention window ago.
 * Database rows survive as history — only storage bytes are reclaimed.
 */
class PruneSentMedia extends Command
{
    protected $signature = 'wahabuddy:prune-sent-media
        {--force : Run now, ignoring the schedule gate (the enabled flag always applies)}
        {--dry-run : Report eligible media without deleting anything}
        {--max=500 : Maximum number of files purged in one run}';

    protected $description = 'Purge storage of media whose referencing stories/broadcasts are terminal past retention';

    public function handle(SentMediaCleanup $cleanup): int
    {
        $settings = AppSetting::singleton();

        if ($this->option('dry-run')) {
            $query = $cleanup->eligibleQuery($settings);
            $files = (clone $query)->count();
            $bytes = (clone $query)->sum('size_bytes');

            $this->info(sprintf('%d file(s), %d bytes eligible for cleanup.', $files, $bytes));

            return self::SUCCESS;
        }

        // The enabled flag is the master switch: it binds scheduled ticks
        // and manual runs alike.
        if (! $settings->media_cleanup_enabled) {
            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            // Fresh installs (and the first tick after enabling) initialize
            // the schedule first so the operator gets one maintenance window
            // to review the eligible estimate before anything is deleted.
            if ($settings->media_cleanup_next_run_at === null) {
                $settings
                    ->fill(['media_cleanup_next_run_at' => $cleanup->nextRunAt(now()->addMinute(), $settings)])
                    ->save();

                return self::SUCCESS;
            }

            if (! $cleanup->isDue($settings)) {
                return self::SUCCESS;
            }
        }

        // Manual and scheduled invocations share one lock so a button press
        // can never race the scheduler tick.
        $lock = Cache::lock('wahabuddy:prune-sent-media', 600);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $this->purgeEligible($cleanup, $settings);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function purgeEligible(SentMediaCleanup $cleanup, AppSetting $settings): void
    {
        $max = max(1, (int) $this->option('max'));
        $trigger = $this->option('force') ? 'manual' : 'scheduled';

        $run = MediaCleanupRun::start($trigger);

        $media = $cleanup
            ->eligibleQuery($settings)
            ->orderBy('id')
            ->limit($max)
            ->get();

        $purged = 0;
        $failed = 0;
        $bytesFreed = 0;

        foreach ($media as $file) {
            if ($cleanup->purge($file)) {
                $purged++;
                $bytesFreed += (int) $file->size_bytes;
            } else {
                $failed++;
            }
        }

        $capped = $media->count() === $max && $cleanup->eligibleQuery($settings)->exists();

        $nextRunAt = $capped
            ? now()->addMinutes(SentMediaCleanup::CAP_RESCHEDULE_MINUTES)
            : $cleanup->nextRunAt(now(), $settings);

        $settings
            ->fill([
                'media_cleanup_last_run_at' => now(),
                'media_cleanup_next_run_at' => $nextRunAt,
            ])
            ->save();

        $message = sprintf(
            'Purged %d file(s), freed %d byte(s)%s%s.',
            $purged,
            $bytesFreed,
            $failed > 0 ? ", {$failed} delete(s) failed" : '',
            $capped ? '; more eligible files remain, next drain soon' : '',
        );

        $run->finish($purged, $bytesFreed, $failed, $capped, $message);
        MediaCleanupRun::pruneToLimit();

        $this->info($message);
    }
}
