<?php

namespace Tests\Feature;

use App\Enums\BroadcastStatus;
use App\Enums\StoryStatus;
use App\Models\AppSetting;
use App\Models\Broadcast;
use App\Models\MediaCleanupRun;
use App\Models\MediaFile;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaUrlResolver;
use App\Services\Media\SentMediaCleanup;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * wahabuddy:prune-sent-media behavior: storage of fully-terminal media is
 * purged after the retention window, records survive as history.
 */
class MediaCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function makeMedia(string $path, int $bytes = 100): MediaFile
    {
        Storage::disk('public')->put($path, 'x');

        return MediaFile::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/jpeg',
            'size_bytes' => $bytes,
            'created_by' => $this->admin()->id,
        ]);
    }

    private function makeStory(MediaFile $media, StoryStatus $status, ?CarbonInterface $updatedAt = null): Story
    {
        $story = Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->subDays(10),
            'status' => $status,
            'sent_at' => $status === StoryStatus::Sent ? now()->subDays(10) : null,
            'connection_revision' => 1,
        ]);

        if ($updatedAt !== null) {
            $story->forceFill(['updated_at' => $updatedAt])->save();
        }

        return $story;
    }

    private function makeBroadcast(MediaFile $media, BroadcastStatus $status, ?CarbonInterface $updatedAt = null): Broadcast
    {
        $broadcast = Broadcast::create([
            'name' => 'Campaign',
            'type' => 'image',
            'media_file_id' => $media->id,
            'template_text' => 'Hello!',
            'status' => $status,
            'connection_revision' => 1,
        ]);

        if ($updatedAt !== null) {
            $broadcast->forceFill(['updated_at' => $updatedAt])->save();
        }

        return $broadcast;
    }

    public function test_purges_storage_of_sent_media_past_retention_and_keeps_records(): void
    {
        $this->freezeTime();
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/sent.jpg', 500);
        $story = $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing('stories/2026/10/sent.jpg');

        $media->refresh();
        $this->assertNotNull($media->purged_at);
        $this->assertDatabaseHas('stories', ['id' => $story->id, 'media_file_id' => $media->id]);
    }

    public function test_keeps_media_of_pending_and_recently_sent_stories(): void
    {
        Storage::fake('public');
        $pending = $this->makeMedia('stories/2026/10/pending.jpg');
        $this->makeStory($pending, StoryStatus::Pending);

        $freshlySent = $this->makeMedia('stories/2026/10/fresh.jpg');
        $this->makeStory($freshlySent, StoryStatus::Sent, now()->subDay());

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/pending.jpg');
        Storage::disk('public')->assertExists('stories/2026/10/fresh.jpg');

        $this->assertDatabaseHas('media_files', ['id' => $pending->id, 'purged_at' => null]);
        $this->assertDatabaseHas('media_files', ['id' => $freshlySent->id, 'purged_at' => null]);
    }

    public function test_keeps_unreferenced_uploads_for_orphan_prune(): void
    {
        Storage::fake('public');
        $orphan = $this->makeMedia('stories/2026/10/orphan.jpg');

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/orphan.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $orphan->id, 'purged_at' => null]);
    }

    public function test_keeps_shared_media_while_any_reference_is_active(): void
    {
        Storage::fake('public');
        $shared = $this->makeMedia('stories/2026/10/shared.jpg');
        $this->makeStory($shared, StoryStatus::Sent, now()->subDays(4));
        $this->makeBroadcast($shared, BroadcastStatus::Queued, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/shared.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $shared->id, 'purged_at' => null]);
    }

    public function test_purges_completed_broadcast_media_after_retention(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/broadcast.jpg');
        $this->makeBroadcast($media, BroadcastStatus::Completed, now()->subDays(5));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing('stories/2026/10/broadcast.jpg');
        $this->assertNotNull($media->refresh()->purged_at);
    }

    public function test_includes_canceled_and_expired_media_only_when_enabled(): void
    {
        Storage::fake('public');
        $canceled = $this->makeMedia('stories/2026/10/canceled.jpg');
        $this->makeStory($canceled, StoryStatus::Canceled, now()->subDays(6));

        $expired = $this->makeMedia('stories/2026/10/expired.jpg');
        $this->makeStory($expired, StoryStatus::Expired, now()->subDays(6));

        $unknown = $this->makeMedia('stories/2026/10/unknown.jpg');
        $this->makeStory($unknown, StoryStatus::Unknown, now()->subDays(90));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/canceled.jpg');
        Storage::disk('public')->assertExists('stories/2026/10/expired.jpg');
        Storage::disk('public')->assertExists('stories/2026/10/unknown.jpg');

        AppSetting::singleton()->forceFill(['media_cleanup_include_terminal' => true])->save();

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertMissing('stories/2026/10/canceled.jpg');
        Storage::disk('public')->assertMissing('stories/2026/10/expired.jpg');
        // Unknown outcomes can still heal to sent; their media is never purged.
        Storage::disk('public')->assertExists('stories/2026/10/unknown.jpg');
    }

    public function test_dry_run_deletes_nothing_but_reports(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/dry.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('1 file(s), 100 bytes eligible');

        Storage::disk('public')->assertExists('stories/2026/10/dry.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'purged_at' => null]);
        $this->assertDatabaseCount('media_cleanup_runs', 0);
    }

    public function test_does_nothing_when_cleanup_is_disabled(): void
    {
        Storage::fake('public');
        AppSetting::singleton()->forceFill(['media_cleanup_enabled' => false])->save();

        $media = $this->makeMedia('stories/2026/10/off.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/off.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'purged_at' => null]);
    }

    public function test_skips_when_next_run_is_in_the_future(): void
    {
        Storage::fake('public');
        AppSetting::singleton()->forceFill(['media_cleanup_next_run_at' => now()->addDay()])->save();

        $media = $this->makeMedia('stories/2026/10/future.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media')->assertSuccessful();

        Storage::disk('public')->assertExists('stories/2026/10/future.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'purged_at' => null]);
    }

    public function test_initializes_next_run_on_first_tick_without_purging(): void
    {
        Storage::fake('public');
        $settings = AppSetting::singleton();
        $this->assertNull($settings->media_cleanup_next_run_at);

        $media = $this->makeMedia('stories/2026/10/first.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(30));

        $this->artisan('wahabuddy:prune-sent-media')->assertSuccessful();

        // The operator gets one maintenance window to review before the
        // first purge fires.
        Storage::disk('public')->assertExists('stories/2026/10/first.jpg');
        $this->assertDatabaseHas('media_files', ['id' => $media->id, 'purged_at' => null]);

        $settings->refresh();
        $this->assertNotNull($settings->media_cleanup_next_run_at);
        $this->assertTrue($settings->media_cleanup_next_run_at->isFuture());
    }

    public function test_reruns_skip_media_marked_purged(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/once.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();
        $purgedAt = $media->refresh()->purged_at;

        $this->travel(1)->hours();

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        $this->assertSame($purgedAt->toDateTimeString(), $media->refresh()->purged_at?->toDateTimeString());
    }

    public function test_marks_purged_when_file_is_already_missing(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/vanished.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));
        Storage::disk('public')->delete('stories/2026/10/vanished.jpg');

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        $this->assertNotNull($media->refresh()->purged_at, 'Already-missing files must still be tombstoned so usage stats converge.');
    }

    public function test_leaves_purged_at_null_when_delete_fails(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/stuck.jpg');
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->with('stories/2026/10/stuck.jpg')->andReturn(false);
        $disk->shouldReceive('exists')->with('stories/2026/10/stuck.jpg')->andReturn(true);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        $this->assertNull($media->refresh()->purged_at);
    }

    public function test_caps_files_per_run_and_reschedules_soon(): void
    {
        Storage::fake('public');
        $settings = AppSetting::singleton();

        foreach (range(1, 3) as $i) {
            $media = $this->makeMedia("stories/2026/10/cap-{$i}.jpg");
            $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));
        }

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true, '--max' => 2])->assertSuccessful();

        $this->assertSame(2, MediaFile::whereNotNull('purged_at')->count());
        $this->assertSame(1, MediaFile::whereNull('purged_at')->where('path', 'like', 'stories/2026/10/cap-%')->count());

        $settings->refresh();
        $this->assertNotNull($settings->media_cleanup_next_run_at);
        // A capped run must drain the remainder soon, not at the next cadence slot.
        $this->assertLessThan(30, $settings->media_cleanup_next_run_at->diffInMinutes(now()), 'Capped run should reschedule within minutes.');
    }

    public function test_records_run_history(): void
    {
        Storage::fake('public');
        $media = $this->makeMedia('stories/2026/10/logged.jpg', 250);
        $this->makeStory($media, StoryStatus::Sent, now()->subDays(4));

        $this->artisan('wahabuddy:prune-sent-media', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('media_cleanup_runs', [
            'trigger' => 'manual',
            'status' => 'completed',
            'files_purged' => 1,
            'bytes_freed' => 250,
        ]);
    }

    public function test_preview_url_is_null_for_purged_media(): void
    {
        Storage::fake('public');
        config([
            'app.url' => 'https://app.example.test',
            'filesystems.disks.public.url' => 'https://app.example.test/storage',
        ]);

        $media = $this->makeMedia('stories/2026/10/gone.jpg');
        $media->forceFill(['purged_at' => now()])->save();

        $resolver = app(MediaUrlResolver::class);

        $this->assertNull($resolver->forPreview($media));
        $this->assertNull($resolver->forSend($media));
    }

    public function test_next_run_at_computes_utc_cadence_slots(): void
    {
        // Wednesday, 2026-10-28, 10:00 UTC — today's 03:05 slot has passed.
        $this->travelTo(Carbon::parse('2026-10-28 10:00', 'UTC'));

        $settings = AppSetting::singleton();
        $cleanup = app(SentMediaCleanup::class);

        $assertSlot = function (array $fields, string $expected) use ($settings, $cleanup) {
            $settings->forceFill($fields)->save();

            $this->assertSame(
                $expected,
                $cleanup->nextRunAt(now(), $settings)->utc()->format('Y-m-d H:i'),
                json_encode($fields),
            );
        };

        $assertSlot(['media_cleanup_frequency' => 'daily'], '2026-10-29 03:05');
        $assertSlot(['media_cleanup_frequency' => 'weekly', 'media_cleanup_day' => 4], '2026-10-29 03:05');
        // Same weekday as "now": today's slot passed, next week wins.
        $assertSlot(['media_cleanup_frequency' => 'weekly', 'media_cleanup_day' => 3], '2026-11-04 03:05');
        $assertSlot(['media_cleanup_frequency' => 'monthly', 'media_cleanup_day' => 1], '2026-11-01 03:05');
        $assertSlot(['media_cleanup_frequency' => 'custom', 'media_cleanup_interval_days' => 10], '2026-11-07 03:05');

        $this->travelBack();
    }

    public function test_next_run_at_is_independent_of_app_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 10:00', 'UTC'));
        config(['app.timezone' => 'Asia/Jakarta']);

        $cleanup = app(SentMediaCleanup::class);
        $settings = AppSetting::singleton();
        $settings->forceFill(['media_cleanup_frequency' => 'monthly', 'media_cleanup_day' => 1])->save();

        // The requested calendar day must land on the UTC slot, not drift
        // across the +07:00 zone boundary.
        $this->assertSame('2026-11-01 03:05', $cleanup->nextRunAt(now(), $settings)->utc()->format('Y-m-d H:i'));

        $this->travelBack();
    }

    public function test_run_history_is_pruned_to_the_newest_100(): void
    {
        foreach (range(1, 103) as $i) {
            MediaCleanupRun::start('scheduled')->finish($i, $i * 10, 0, false, "run {$i}");
        }

        // Auto-increment ids never restart inside the test transaction, so
        // compare against the ids actually created, not against 1..103.
        $firstId = (int) MediaCleanupRun::query()->min('id');
        $lastId = (int) MediaCleanupRun::query()->max('id');

        MediaCleanupRun::pruneToLimit();

        $this->assertSame(100, MediaCleanupRun::count());
        $this->assertSame($lastId, (int) MediaCleanupRun::query()->max('id'));
        $this->assertDatabaseMissing('media_cleanup_runs', ['id' => $firstId]);
    }
}
