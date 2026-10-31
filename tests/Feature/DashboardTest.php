<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Jobs\RecordWorkerHeartbeatJob;
use App\Models\AppSetting;
use App\Models\Story;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makeStory(array $overrides = []): Story
    {
        $story = new Story;
        $story->forceFill(array_merge([
            'type' => 'text',
            'text' => 'x',
            'scheduled_at' => now(),
            'status' => StoryStatus::Pending,
            'attempts' => 0,
            'connection_revision' => 1,
        ], $overrides));
        $story->save();

        return $story;
    }

    public function test_counts_respect_display_timezone_and_unknown_is_separate(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00'); // 17:00 Jakarta
        AppSetting::singleton()->forceFill(['display_timezone' => 'Asia/Jakarta'])->save();

        // "Today" in Asia/Jakarta spans 2026-11-01 00:00..23:59 +07.
        $todaySent = $this->makeStory([
            'status' => StoryStatus::Sent,
            'sent_at' => Carbon::rawParse('2026-11-01 08:00:00', 'Asia/Jakarta')->utc(),
            'scheduled_at' => Carbon::rawParse('2026-11-01 07:00:00', 'Asia/Jakarta')->utc(),
        ]);
        // 20:00 Jakarta on Nov 1 is still Nov 1 in the display timezone.
        $this->makeStory(['scheduled_at' => Carbon::rawParse('2026-11-01 20:00:00', 'Asia/Jakarta')->utc()]);

        $pendingFuture = $this->makeStory(['scheduled_at' => now()->addHour()]);
        $failed = $this->makeStory(['status' => StoryStatus::Failed, 'scheduled_at' => now()->subDays(2)]);
        $failedMax = $this->makeStory(['status' => StoryStatus::FailedMax, 'scheduled_at' => now()->subDays(2)]);
        $unknown = $this->makeStory(['status' => StoryStatus::Unknown, 'scheduled_at' => now()->subDays(2)]);
        $sentMonth = $this->makeStory([
            'status' => StoryStatus::Sent,
            'scheduled_at' => now()->subDays(2),
            'sent_at' => Carbon::rawParse('2026-10-20 09:00:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($this->admin());

        $page = $this->get(route('dashboard'));
        $page->assertOk();

        $props = json_decode(json_encode($page->inertiaPage()['props']), true);

        $this->assertSame(3, $props['counts']['today_total']);
        $this->assertSame(1, $props['counts']['sent_today']);
        $this->assertSame(2, $props['counts']['pending']);
        $this->assertSame(2, $props['counts']['failed']);
        $this->assertSame(1, $props['counts']['failed_max']);
        // Unknown must never be folded into failed.
        $this->assertSame(1, $props['counts']['unknown']);
        // Current display month (November Jakarta) excludes the Oct 20 send.
        $this->assertSame(1, $props['counts']['sent_month']);

        // Upcoming is ordered by scheduled time ascending: the failed retry
        // (scheduled two days ago) comes first; all pending rows are listed.
        $this->assertSame($failed->id, $props['upcoming'][0]['id']);
        $upcomingIds = array_column($props['upcoming'], 'id');
        $this->assertContains($pendingFuture->id, $upcomingIds);
    }

    public function test_stale_worker_heartbeat_is_visible(): void
    {
        $this->actingAs($this->admin());

        // Fresh heartbeat.
        Cache::put(RecordWorkerHeartbeatJob::CACHE_KEY, now()->toAtomString(), now()->addDay());
        $props = json_decode(json_encode($this->get(route('dashboard'))->inertiaPage()['props']), true);
        $this->assertFalse($props['worker']['stale']);

        // Heartbeat older than the interval means no worker is consuming.
        Cache::put(RecordWorkerHeartbeatJob::CACHE_KEY, now()->subMinutes(15)->toAtomString(), now()->addDay());
        $props = json_decode(json_encode($this->get(route('dashboard'))->inertiaPage()['props']), true);
        $this->assertTrue($props['worker']['stale']);
    }

    public function test_manual_scheduler_trigger_auth_and_queued_dispatch(): void
    {
        // Guests cannot trigger the scheduler.
        $this->post(route('scheduler.run'))->assertRedirect('/login');

        Queue::fake();

        // Authorized: dispatches the same dispatcher as a queued command —
        // never executing HTTP work inside the UI request.
        $this->actingAs($this->admin());
        $this->post(route('scheduler.run'))->assertRedirect();

        Queue::assertPushed(QueuedCommand::class, function ($job): bool {
            $data = (fn () => $this->data)->call($job);

            return ($data[0] ?? null) === 'waha:send-stories';
        });
    }
}
