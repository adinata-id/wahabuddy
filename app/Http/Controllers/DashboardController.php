<?php

namespace App\Http\Controllers;

use App\Enums\StoryStatus;
use App\Jobs\RecordWorkerHeartbeatJob;
use App\Models\AppSetting;
use App\Models\SchedulerRun;
use App\Models\Story;
use App\Models\StoryLog;
use App\Services\Waha\ConnectionRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ConnectionRepository $connections,
    ) {}

    /**
     * Operational overview. Counts are aggregated in the selected display
     * timezone; unknown is reported separately from failed (spec J).
     */
    public function index(Request $request): Response
    {
        $timezone = AppSetting::singleton()->display_timezone;

        $dayStart = now($timezone)->startOfDay()->utc();
        $dayEnd = now($timezone)->endOfDay()->utc();
        $monthStart = now($timezone)->startOfMonth()->utc();

        $countByStatus = fn (StoryStatus ...$statuses) => Story::query()
            ->whereIn('status', array_map(fn (StoryStatus $status) => $status->value, $statuses))
            ->count();

        $todayTotal = Story::query()->whereBetween('scheduled_at', [$dayStart, $dayEnd])->count();
        $sentToday = Story::query()
            ->where('status', StoryStatus::Sent->value)
            ->whereBetween('sent_at', [$dayStart, $dayEnd])
            ->count();
        $sentMonth = Story::query()
            ->where('status', StoryStatus::Sent->value)
            ->whereBetween('sent_at', [$monthStart, $dayEnd])
            ->count();

        $lastRun = SchedulerRun::latest('id')->first();
        $heartbeatAt = null;
        $workerStale = true;

        $cachedHeartbeat = cache(RecordWorkerHeartbeatJob::CACHE_KEY);
        if (is_string($cachedHeartbeat) && $cachedHeartbeat !== '') {
            $heartbeatAt = $cachedHeartbeat;
            $workerStale = now()->parse($cachedHeartbeat)->lt(now()->subMinutes(10));
        }

        $connection = $this->connections->current();
        $revisionHeld = Story::query()
            ->whereIn('status', [StoryStatus::Pending, StoryStatus::Failed])
            ->where('connection_revision', '!=', $connection->revision)
            ->count();

        $upcoming = Story::query()
            ->whereIn('status', [StoryStatus::Pending, StoryStatus::Failed])
            ->whereNull('attempt_token')
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get()
            ->map(fn (Story $story) => [
                'id' => $story->id,
                'type' => $story->type->value,
                'text' => $story->text !== null && $story->text !== '' ? mb_substr($story->text, 0, 60) : null,
                'status' => $story->status->value,
                'scheduled_at_display' => $story->scheduled_at->timezone($timezone)->format('Y-m-d H:i'),
                'held' => $story->connection_revision !== $connection->revision,
            ]);

        $activity = StoryLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->map(fn (StoryLog $log) => [
                'id' => $log->id,
                'story_id' => $log->story_id,
                'event' => $log->event,
                'message' => $log->message,
                'created_at_display' => $log->created_at?->timezone($timezone)->format('Y-m-d H:i'),
            ]);

        return Inertia::render('dashboard', [
            'counts' => [
                'today_total' => $todayTotal,
                'sent_today' => $sentToday,
                'sent_month' => $sentMonth,
                'pending' => $countByStatus(StoryStatus::Pending),
                'sending' => $countByStatus(StoryStatus::Sending),
                'failed' => $countByStatus(StoryStatus::Failed, StoryStatus::FailedMax),
                'failed_max' => $countByStatus(StoryStatus::FailedMax),
                'unknown' => $countByStatus(StoryStatus::Unknown),
                'expired' => $countByStatus(StoryStatus::Expired),
                'canceled' => $countByStatus(StoryStatus::Canceled),
            ],
            'upcoming' => $upcoming,
            'activity' => $activity,
            'scheduler' => [
                'last_run_at_display' => $lastRun?->started_at?->timezone($timezone)->format('Y-m-d H:i:s'),
                'status' => $lastRun?->status?->value,
                'last_error' => $lastRun?->last_error,
            ],
            'worker' => [
                'heartbeat_at' => $heartbeatAt,
                'stale' => $workerStale,
            ],
            'connection_warning' => [
                'untested' => $connection->isConfigured() && $connection->last_test_status !== 'working',
                'unconfigured' => ! $connection->isConfigured(),
                'held_stories' => $revisionHeld,
                'revision' => (int) $connection->revision,
            ],
        ]);
    }

    /**
     * "Run scheduler now": the same dispatcher, queued — never synchronous
     * HTTP work inside a UI request (spec G2). Throttled on the route.
     */
    public function runScheduler(): RedirectResponse
    {
        Artisan::queue('waha:send-stories');

        return back()->with('status', 'scheduler-queued');
    }
}
