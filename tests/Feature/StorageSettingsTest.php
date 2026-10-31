<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\AppSetting;
use App\Models\MediaFile;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/**
 * Storage & auto-cleanup settings: saving through the preferences endpoint,
 * the S3 gate, the settings-page summary, and the manual run button.
 */
class StorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * The preferences endpoint is throttled 10/min per user and the cache
     * store survives across tests in one process; step the window forward
     * between bursts of saves.
     */
    private function advanceThrottleWindow(): void
    {
        $this->travel(61)->seconds();
    }

    private function eligibleMedia(int $bytes = 100): MediaFile
    {
        Storage::fake('public');
        Storage::disk('public')->put('stories/2026/10/eligible.jpg', 'x');

        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/eligible.jpg',
            'original_name' => 'eligible.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $bytes,
            'created_by' => $this->admin()->id,
        ]);

        $story = Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->subDays(10),
            'status' => StoryStatus::Sent,
            'sent_at' => now()->subDays(10),
            'connection_revision' => 1,
        ]);
        $story->forceFill(['updated_at' => now()->subDays(4)])->save();

        return $media;
    }

    public function test_save_rejects_s3_disk_until_fully_configured(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('settings.index'))
            ->put(route('settings.preferences.update'), ['media_disk' => 's3'])
            ->assertSessionHasErrors('media_disk');

        $this->assertSame('public', AppSetting::singleton()->media_disk);
    }

    public function test_save_public_disk_updates_setting_and_reschedules_next_run(): void
    {
        $this->actingAs($this->admin());
        AppSetting::singleton()->forceFill(['media_cleanup_next_run_at' => now()->subDay()])->save();

        $this->put(route('settings.preferences.update'), [
            'media_disk' => 'public',
            'media_cleanup_enabled' => true,
            'media_cleanup_frequency' => 'daily',
            'media_cleanup_retention_days' => 3,
            'media_cleanup_include_terminal' => false,
        ])->assertRedirect();

        $settings = AppSetting::singleton()->refresh();
        $this->assertSame('public', $settings->media_disk);
        $this->assertSame('daily', $settings->media_cleanup_frequency);
        $this->assertNotNull($settings->media_cleanup_next_run_at);
        $this->assertTrue($settings->media_cleanup_next_run_at->isFuture(), 'Saving the schedule must reschedule immediately.');
    }

    public function test_save_validates_cadence_shape(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $base = [
            'media_cleanup_enabled' => true,
            'media_cleanup_frequency' => 'weekly',
            'media_cleanup_retention_days' => 3,
        ];

        $this->put(route('settings.preferences.update'), $base)
            ->assertSessionHasErrors('media_cleanup_day');

        $this->put(route('settings.preferences.update'), $base + ['media_cleanup_day' => 7])
            ->assertSessionHasErrors('media_cleanup_day');

        $this->put(route('settings.preferences.update'), ['media_cleanup_frequency' => 'monthly', 'media_cleanup_day' => 29, 'media_cleanup_retention_days' => 3])
            ->assertSessionHasErrors('media_cleanup_day');

        $this->put(route('settings.preferences.update'), ['media_cleanup_frequency' => 'custom', 'media_cleanup_retention_days' => 3])
            ->assertSessionHasErrors('media_cleanup_interval_days');

        $this->put(route('settings.preferences.update'), ['media_cleanup_frequency' => 'weekly', 'media_cleanup_day' => 1, 'media_cleanup_retention_days' => 0])
            ->assertSessionHasErrors('media_cleanup_retention_days');

        $this->put(route('settings.preferences.update'), ['media_cleanup_frequency' => 'hourly', 'media_cleanup_retention_days' => 3])
            ->assertSessionHasErrors('media_cleanup_frequency');
    }

    public function test_settings_page_exposes_storage_summary(): void
    {
        $this->actingAs($this->admin());
        $media = $this->eligibleMedia(250);

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/index')
                ->where('storage.media_disk', 'public')
                ->where('storage.cleanup.enabled', true)
                ->where('storage.cleanup.frequency', 'weekly')
                ->where('storage.cleanup.retention_days', 3)
                ->where('storage.estimate.files', 1)
                ->where('storage.estimate.bytes', 250)
                ->where('storage.capabilities.s3_adapter_installed', class_exists(AwsS3V3Adapter::class))
                ->where('storage.disk_usage', fn ($usage) => collect($usage)->contains(
                    fn ($row) => $row['disk'] === 'public' && (int) $row['files'] === 1 && (int) $row['bytes'] === 250,
                ))
                ->where('storage.recent_runs', [])
            );

        $this->assertSame($media->id, MediaFile::first()->id);
    }

    public function test_run_cleanup_endpoint_purges_eligible_media_now(): void
    {
        $this->actingAs($this->admin());
        $media = $this->eligibleMedia();

        $this->post(route('settings.storage.cleanup-run'))
            ->assertRedirect();

        Storage::disk('public')->assertMissing('stories/2026/10/eligible.jpg');
        $this->assertNotNull($media->refresh()->purged_at);

        $this->assertDatabaseHas('media_cleanup_runs', [
            'trigger' => 'manual',
            'status' => 'completed',
            'files_purged' => 1,
        ]);
    }

    public function test_run_cleanup_endpoint_refuses_when_cleanup_is_disabled(): void
    {
        $this->actingAs($this->admin());
        $media = $this->eligibleMedia();
        AppSetting::singleton()->forceFill(['media_cleanup_enabled' => false])->save();

        $this->post(route('settings.storage.cleanup-run'))
            ->assertSessionHasErrors('storage');

        Storage::disk('public')->assertExists('stories/2026/10/eligible.jpg');
        $this->assertNull($media->refresh()->purged_at);
    }

    public function test_partial_pacing_update_cannot_break_min_max_order(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        AppSetting::singleton()->forceFill([
            'broadcast_delay_min' => 30,
            'broadcast_delay_max' => 60,
        ])->save();

        // Only min is submitted; the invariant must still be checked against
        // the stored max, not just against fields present in the request.
        $this->put(route('settings.preferences.update'), ['broadcast_delay_min' => 500])
            ->assertSessionHasErrors('broadcast_delay_max');

        $this->assertSame(30, AppSetting::singleton()->refresh()->broadcast_delay_min);

        // A consistent partial update goes through.
        $this->put(route('settings.preferences.update'), ['broadcast_delay_min' => 40])
            ->assertRedirect();
        $this->assertSame(40, AppSetting::singleton()->refresh()->broadcast_delay_min);
    }

    public function test_cadence_only_save_reschedules_next_run(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();
        AppSetting::singleton()->forceFill([
            'media_cleanup_frequency' => 'weekly',
            'media_cleanup_day' => 1,
            'media_cleanup_next_run_at' => now()->addDays(30),
        ])->save();

        $this->put(route('settings.preferences.update'), ['media_cleanup_frequency' => 'daily'])
            ->assertRedirect();

        $next = AppSetting::singleton()->refresh()->media_cleanup_next_run_at;

        $this->assertNotNull($next);
        $this->assertTrue($next->isFuture());
        $this->assertLessThan(2, $next->diffInDays(now()), 'A cadence change must not wait out the old interval.');
    }
}
