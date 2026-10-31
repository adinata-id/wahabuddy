<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\MediaFile;
use App\Models\User;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public media base URL setting: WAHA downloads image/video media
 * itself, so local media needs a publicly reachable base URL (e.g. a
 * tunnel). The setting overrides send URLs only — previews keep using the
 * app URL — and a probe endpoint checks the URL from the backend.
 */
class MediaPublicBaseUrlTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * The preferences endpoint is throttled 10/min per user; step the
     * window forward between saves.
     */
    private function advanceThrottleWindow(): void
    {
        $this->travel(61)->seconds();
    }

    private function storedMedia(): MediaFile
    {
        // The fake disk must keep the real public-disk URL config so the
        // fallback assertions match production URL derivation.
        Storage::fake('public', [
            'url' => rtrim((string) config('app.url'), '/').'/storage',
        ]);
        Storage::disk('public')->put('stories/2026/10/pic.png', 'x');

        return MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/pic.png',
            'original_name' => 'pic.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'created_by' => $this->admin()->id,
        ]);
    }

    public function test_save_accepts_and_normalizes_public_base_url(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('settings.index'))
            ->put(route('settings.preferences.update'), [
                'media_public_base_url' => 'https://tunnel.example.com/',
            ])
            ->assertRedirect();

        $this->assertSame(
            'https://tunnel.example.com',
            AppSetting::singleton()->media_public_base_url,
        );
    }

    public function test_save_rejects_a_non_http_url(): void
    {
        $this->actingAs($this->admin());

        $this->from(route('settings.index'))
            ->put(route('settings.preferences.update'), [
                'media_public_base_url' => 'ftp://not-a-web-url',
            ])
            ->assertSessionHasErrors('media_public_base_url');
    }

    public function test_save_allows_clearing_public_base_url(): void
    {
        $settings = AppSetting::singleton();
        $settings->fill(['media_public_base_url' => 'https://tunnel.example.com'])->save();
        $this->actingAs($this->admin());
        $this->advanceThrottleWindow();

        $this->from(route('settings.index'))
            ->put(route('settings.preferences.update'), [
                'media_public_base_url' => '',
            ])
            ->assertRedirect();

        $this->assertNull($settings->refresh()->media_public_base_url);
    }

    public function test_send_urls_use_the_configured_public_base_url(): void
    {
        $media = $this->storedMedia();
        AppSetting::singleton()->fill([
            'media_public_base_url' => 'https://tunnel.example.com',
        ])->save();

        $url = app(MediaUrlResolver::class)->forSend($media);

        $this->assertSame(
            'https://tunnel.example.com/stories/2026/10/pic.png',
            $url,
        );
    }

    public function test_send_urls_fall_back_to_the_disk_url_when_unset(): void
    {
        $media = $this->storedMedia();

        $url = app(MediaUrlResolver::class)->forSend($media);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/stories/2026/10/pic.png',
            $url,
        );
    }

    public function test_preview_urls_keep_using_the_disk_url(): void
    {
        $media = $this->storedMedia();
        AppSetting::singleton()->fill([
            'media_public_base_url' => 'https://tunnel.example.com',
        ])->save();

        $url = app(MediaUrlResolver::class)->forPreview($media);

        $this->assertSame(
            rtrim(config('app.url'), '/').'/storage/stories/2026/10/pic.png',
            $url,
        );
    }

    public function test_check_public_url_reports_a_reachable_base(): void
    {
        $this->actingAs($this->admin());
        Http::fake(['tunnel.example.com' => Http::response('', 200)]);

        $response = $this->postJson(
            route('settings.storage.check-public-url'),
            ['base_url' => 'https://tunnel.example.com'],
        );

        $response->assertOk()->assertJson(['reachable' => true, 'status' => 200]);
    }

    public function test_check_public_url_reports_a_failing_base(): void
    {
        $this->actingAs($this->admin());
        Http::fake(['tunnel.example.com' => Http::response('boom', 502)]);

        $response = $this->postJson(
            route('settings.storage.check-public-url'),
            ['base_url' => 'https://tunnel.example.com'],
        );

        $response->assertOk()->assertJson(['reachable' => false, 'status' => 502]);
    }

    public function test_check_public_url_requires_authentication(): void
    {
        $this->postJson(
            route('settings.storage.check-public-url'),
            ['base_url' => 'https://tunnel.example.com'],
        )->assertUnauthorized();
    }
}
