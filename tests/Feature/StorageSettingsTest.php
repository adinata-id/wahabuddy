<?php

namespace Tests\Feature;

use App\Enums\StoryStatus;
use App\Models\AppSetting;
use App\Models\MediaFile;
use App\Models\S3Connection;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_save_updates_cleanup_settings_and_reschedules_next_run(): void
    {
        $this->actingAs($this->admin());
        AppSetting::singleton()->forceFill(['media_cleanup_next_run_at' => now()->subDay()])->save();

        $this->put(route('settings.preferences.update'), [
            'media_cleanup_enabled' => true,
            'media_cleanup_frequency' => 'daily',
            'media_cleanup_retention_days' => 3,
            'media_cleanup_include_terminal' => false,
        ])->assertRedirect();

        $settings = AppSetting::singleton()->refresh();
        $this->assertSame('daily', $settings->media_cleanup_frequency);
        $this->assertNotNull($settings->media_cleanup_next_run_at);
        $this->assertTrue($settings->media_cleanup_next_run_at->isFuture(), 'Saving the schedule must reschedule immediately.');
    }

    /**
     * The removed local-disk fields (media_disk, media_public_base_url) are
     * ignored, not validated: partial saves from an outdated open tab must
     * still succeed.
     */
    public function test_save_ignores_the_removed_disk_and_public_base_url_fields(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('settings.index'))
            ->put(route('settings.preferences.update'), [
                'media_disk' => 's3',
                'media_public_base_url' => 'ftp://not-a-web-url',
                'media_cleanup_enabled' => true,
                'media_cleanup_frequency' => 'daily',
                'media_cleanup_retention_days' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('daily', AppSetting::singleton()->refresh()->media_cleanup_frequency);
    }

    public function test_check_public_url_endpoint_is_removed(): void
    {
        $this->actingAs($this->admin());

        $this->post('/settings/storage/check-public-url', ['base_url' => 'https://tunnel.example.com'])
            ->assertNotFound();
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

    public function test_save_updates_s3_connection_settings(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $this->from(route('settings.index'))
            ->put(route('settings.storage.update'), [
                'enabled' => true,
                'key' => 'AKIA-example',
                'secret' => 'top-secret',
                'bucket' => 'media-bucket',
                'region' => 'auto',
                'endpoint' => 'https://acct.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = S3Connection::singleton()->refresh();
        $this->assertTrue($row->enabled);
        $this->assertSame('AKIA-example', $row->key);
        $this->assertSame('top-secret', $row->secret);
        $this->assertSame('media-bucket', $row->bucket);
        $this->assertSame('https://acct.r2.cloudflarestorage.com', $row->endpoint);
    }

    public function test_save_keeps_blank_secret_and_clears_blank_non_secret_fields(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        S3Connection::singleton()->forceFill([
            'key' => 'old-key', 'secret' => 'old-secret', 'bucket' => 'old-bucket',
            'endpoint' => 'https://old.example.com', 'region' => 'us-east-1',
        ])->save();

        $this->put(route('settings.storage.update'), [
            'enabled' => true,
            'key' => '',
            'secret' => '',
            'bucket' => 'new-bucket',
            'region' => '',
            'endpoint' => '',
            'use_path_style_endpoint' => false,
        ])->assertRedirect();

        $row = S3Connection::singleton()->refresh();
        $this->assertSame('old-secret', $row->secret, 'Blank secret keeps the stored value.');
        $this->assertNull($row->key, 'Blank key clears the stored value.');
        $this->assertNull($row->endpoint, 'Blank endpoint clears the stored value.');
        $this->assertSame('new-bucket', $row->bucket);
        $this->assertSame('auto', $row->region, 'Blank region falls back to auto.');
        $this->assertFalse((bool) $row->use_path_style_endpoint);
    }

    public function test_save_rejects_metadata_and_non_http_endpoints(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $this->put(route('settings.storage.update'), ['endpoint' => 'http://169.254.169.254/latest/meta-data'])
            ->assertSessionHasErrors('endpoint');

        $this->advanceThrottleWindow();
        $this->put(route('settings.storage.update'), ['endpoint' => 'ftp://storage.example.com'])
            ->assertSessionHasErrors('endpoint');
    }

    public function test_save_invalidates_stale_last_test_result(): void
    {
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        S3Connection::singleton()->forceFill([
            'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => null,
            'use_path_style_endpoint' => true,
            'last_test_status' => 'passed', 'last_test_at' => now(),
        ])->save();

        // Same effective config → result survives.
        $this->put(route('settings.storage.update'), [
            'enabled' => true, 'key' => 'k', 'secret' => '', 'bucket' => 'b',
            'region' => 'auto', 'endpoint' => '', 'use_path_style_endpoint' => true,
        ])->assertRedirect();
        $this->assertSame('passed', S3Connection::singleton()->refresh()->last_test_status);

        // Changed config → result cleared.
        $this->advanceThrottleWindow();
        $this->put(route('settings.storage.update'), [
            'enabled' => true, 'key' => 'k2', 'secret' => '', 'bucket' => 'b',
            'region' => 'auto', 'endpoint' => '', 'use_path_style_endpoint' => true,
        ])->assertRedirect();
        $this->assertNull(S3Connection::singleton()->refresh()->last_test_status);
    }

    public function test_clear_secret_endpoint_removes_only_the_secret(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill(['key' => 'k', 'secret' => 's', 'bucket' => 'b'])->save();

        $this->delete(route('settings.storage.clear-secret'))->assertRedirect();

        $row = S3Connection::singleton()->refresh();
        $this->assertNull($row->secret);
        $this->assertSame('k', $row->key);
    }

    public function test_settings_page_exposes_connection_props_without_secrets(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'AKIA-abcd1234', 'secret' => 'top-secret', 'bucket' => 'b',
        ])->save();

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.connection.enabled', true)
                ->where('storage.connection.has_key', true)
                ->where('storage.connection.has_secret', true)
                ->where('storage.connection.configured', true)
                ->where('storage.connection.credentials_unreadable', false)
                ->where('storage.connection.key_hint', fn ($hint) => str_ends_with((string) $hint, '1234'))
                ->where('storage.connection.bucket', 'b'));

        $response = $this->get(route('settings.index'))->getContent();
        $this->assertStringNotContainsString('top-secret', $response);
        $this->assertStringNotContainsString('AKIA-abcd1234', $response);
    }

    public function test_settings_page_flags_unreadable_credentials(): void
    {
        $this->actingAs($this->admin());

        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        // Simulate APP_KEY rotation.
        DB::table('s3_connections')->update(['secret' => 'not-valid-ciphertext']);

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('storage.connection.credentials_unreadable', true)
                ->where('storage.connection.configured', false));
    }

    public function test_storage_routes_redirect_guests(): void
    {
        $this->put(route('settings.storage.update'), [])->assertRedirect('login');
        $this->delete(route('settings.storage.clear-secret'))->assertRedirect('login');
    }
}
