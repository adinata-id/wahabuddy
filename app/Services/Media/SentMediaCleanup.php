<?php

namespace App\Services\Media;

use App\Enums\BroadcastStatus;
use App\Enums\StoryStatus;
use App\Models\AppSetting;
use App\Models\Broadcast;
use App\Models\MediaFile;
use App\Models\Story;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Decides which media storage may be purged and when the cleanup runs.
 *
 * A media file is purgeable when at least one story/broadcast references
 * it, every referencing row is in a terminal state within the cleanup
 * scope, and all of those rows have been terminal for longer than the
 * retention window. Unreferenced uploads stay untouched — the orphan
 * prune owns those. Unknown outcomes are never purgeable: they can still
 * heal to sent.
 */
final class SentMediaCleanup
{
    /** Maintenance slot (UTC) shared with the other prune commands. */
    public const MAINTENANCE_HOUR = 3;

    public const MAINTENANCE_MINUTE = 5;

    /** A capped run drains the remainder this many minutes later. */
    public const CAP_RESCHEDULE_MINUTES = 15;

    /**
     * Media whose storage can be purged right now under current settings.
     *
     * @return Builder<MediaFile>
     */
    public function eligibleQuery(?AppSetting $settings = null): Builder
    {
        $settings ??= AppSetting::singleton();
        $cutoff = now()->subDays(max(1, (int) $settings->media_cleanup_retention_days));

        [$storyScope, $broadcastScope] = $this->terminalScopes($settings);

        $anyStoryRef = fn ($query) => $query
            ->selectRaw('1')
            ->from('stories')
            ->whereColumn('stories.media_file_id', 'media_files.id');

        $anyBroadcastRef = fn ($query) => $query
            ->selectRaw('1')
            ->from('broadcasts')
            ->whereColumn('broadcasts.media_file_id', 'media_files.id');

        // A reference is "kept" while its row is outside the purge scope or
        // has not been terminal long enough (grace measured from updated_at,
        // which is >= sent_at/finished_at and errs towards keeping).
        $keptStory = fn ($query) => $anyStoryRef($query)->where(
            fn ($inner) => $inner
                ->whereNotIn('stories.status', $storyScope)
                ->orWhere('stories.updated_at', '>', $cutoff),
        );

        $keptBroadcast = fn ($query) => $anyBroadcastRef($query)->where(
            fn ($inner) => $inner
                ->whereNotIn('broadcasts.status', $broadcastScope)
                ->orWhere('broadcasts.updated_at', '>', $cutoff),
        );

        return MediaFile::query()
            ->whereNull('purged_at')
            ->where(
                fn ($query) => $query
                    ->whereExists(fn ($q) => $anyStoryRef($q))
                    ->orWhereExists(fn ($q) => $anyBroadcastRef($q)),
            )
            ->whereNotExists(fn ($q) => $keptStory($q))
            ->whereNotExists(fn ($q) => $keptBroadcast($q));
    }

    /**
     * Delete the stored object and tombstone the row. The row always
     * survives as history; only the bytes go.
     *
     * Disks are configured with throw=false, so the delete result is the
     * primary signal; only a false return needs the follow-up existence
     * check (distinguishing a real failure from an already-absent path,
     * which still counts as purged so usage stats converge). Disk
     * resolution happens inside the try: a disk whose driver cannot be
     * resolved (adapter removed) fails this one file instead of aborting
     * the whole run.
     */
    public function purge(MediaFile $media): bool
    {
        try {
            $disk = Storage::disk($media->disk);

            if ($disk->delete($media->path) === false && $disk->exists($media->path)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $media->fill(['purged_at' => now()])->save();

        return true;
    }

    /**
     * Whether the scheduled tick should run the cleanup now. A null
     * next_run_at (fresh install or first tick after enabling) is not due.
     */
    public function isDue(?AppSetting $settings = null): bool
    {
        $settings ??= AppSetting::singleton();
        $next = $settings->media_cleanup_next_run_at;

        return $next !== null && $next->lte(now());
    }

    /**
     * Next cadence occurrence of the maintenance slot after $after. All
     * calendar math happens in UTC regardless of the app timezone, so the
     * configured weekday/day-of-month can never drift across zone shifts.
     */
    public function nextRunAt(CarbonInterface $after, ?AppSetting $settings = null): Carbon
    {
        $settings ??= AppSetting::singleton();
        $after = Carbon::instance($after->toDateTime())->utc();

        $slot = fn (Carbon $day): Carbon => $day
            ->copy()
            ->setTime(self::MAINTENANCE_HOUR, self::MAINTENANCE_MINUTE);

        $candidate = match ($settings->media_cleanup_frequency) {
            'daily' => $this->nextDailySlot($after, $slot),
            'weekly' => $this->nextWeeklySlot($after, $slot, (int) ($settings->media_cleanup_day ?? 1)),
            'monthly' => $this->nextMonthlySlot($after, $slot, (int) ($settings->media_cleanup_day ?? 1)),
            default => $slot($after->copy()->addDays(max(1, (int) ($settings->media_cleanup_interval_days ?? 1)))->startOfDay()),
        };

        if ($candidate->lte($after)) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * @return array{0: list<StoryStatus>, 1: list<BroadcastStatus>}
     */
    private function terminalScopes(AppSetting $settings): array
    {
        $storyScope = $settings->media_cleanup_include_terminal
            ? [StoryStatus::Sent, StoryStatus::FailedMax, StoryStatus::Expired, StoryStatus::Canceled]
            : [StoryStatus::Sent];

        $broadcastScope = $settings->media_cleanup_include_terminal
            ? [BroadcastStatus::Completed, BroadcastStatus::Canceled]
            : [BroadcastStatus::Completed];

        return [$storyScope, $broadcastScope];
    }

    /**
     * @param  callable(Carbon): Carbon  $slot
     */
    private function nextDailySlot(Carbon $after, callable $slot): Carbon
    {
        return $slot($after->copy()->startOfDay())->gt($after)
            ? $slot($after->copy()->startOfDay())
            : $slot($after->copy()->addDay()->startOfDay());
    }

    /**
     * @param  callable(Carbon): Carbon  $slot
     */
    private function nextWeeklySlot(Carbon $after, callable $slot, int $dayOfWeek): Carbon
    {
        $cursor = $after->copy()->startOfDay();

        for ($i = 0; $i < 8; $i++) {
            if ((int) $cursor->dayOfWeek === $dayOfWeek % 7 && $slot($cursor)->gt($after)) {
                return $slot($cursor);
            }

            $cursor = $cursor->addDay();
        }

        return $slot($cursor);
    }

    /**
     * @param  callable(Carbon): Carbon  $slot
     */
    private function nextMonthlySlot(Carbon $after, callable $slot, int $dayOfMonth): Carbon
    {
        $dayOfMonth = max(1, min(28, $dayOfMonth));
        $cursor = $after->copy()->startOfDay()->setDay(min($dayOfMonth, (int) $after->format('t')));

        for ($i = 0; $i < 3; $i++) {
            if ((int) $cursor->day === $dayOfMonth && $slot($cursor)->gt($after)) {
                return $slot($cursor);
            }

            $cursor = $cursor->addMonthNoOverflow()->startOfDay()->setDay($dayOfMonth);
        }

        return $slot($cursor);
    }
}
