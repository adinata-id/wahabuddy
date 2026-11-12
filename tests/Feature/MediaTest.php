<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\S3Connection;
use App\Models\Story;
use App\Models\User;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    public const TINY_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwcJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAA/AKgA/9k=';

    public const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function jpeg(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::TINY_JPEG));
    }

    public function test_rejects_mime_mismatch_and_oversize(): void
    {
        $this->actingAs($this->admin());

        // Text content disguised with a .jpg extension must be rejected by
        // server-side content detection, not by trusting the client.
        $disguised = UploadedFile::fake()->createWithContent('evil.jpg', 'this is actually plain text');

        $this->postJson(route('media.store'), ['file' => $disguised])
            ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Unsupported media type'));

        $this->assertDatabaseCount('media_files', 0);

        $oversize = UploadedFile::fake()->create('big.jpg', 51201); // KiB > 50 MiB

        $this->postJson(route('media.store'), ['file' => $oversize])
            ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'greater than'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_rejects_unsupported_formats(): void
    {
        $this->actingAs($this->admin());

        foreach (['photo.gif' => 'GIF89a'.str_repeat('a', 32), 'doc.pdf' => '%PDF-1.4 test'] as $name => $content) {
            $this->postJson(route('media.store'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])
                ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Unsupported media type'));
        }

        $this->assertDatabaseCount('media_files', 0);
    }

    /**
     * Enabled storage row + swapped fake adapter — the upload gate, the
     * resolver, and destroy all speak to this. Credentials are distinctive
     * so tests can assert they never leak into URLs or responses.
     */
    private function enableS3Storage(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => true,
            'key' => 'AKIA-PROBE-KEY-1234',
            'secret' => 'probe-secret-value-5678',
            'bucket' => 'probe-bucket',
        ])->save();
        $this->swapMediaStorageWithFake();
    }

    public function test_accepts_supported_images_with_detected_mime(): void
    {
        $this->enableS3Storage();
        $this->actingAs($this->admin());

        $response = $this->postJson(route('media.store'), ['file' => $this->jpeg()]);

        $response->assertOk()->assertJsonPath('mime_type', 'image/jpeg');

        $media = MediaFile::first();
        $this->assertSame('s3', $media->disk);
        $this->assertSame('photo.jpg', $media->original_name);
        $this->assertMatchesRegularExpression('#^stories/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#', $media->path);

        Storage::disk('s3')->assertExists($media->path);

        // The response URL must never embed the storage credentials.
        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringNotContainsString('AKIA-PROBE-KEY-1234', $url);
        $this->assertStringNotContainsString('probe-secret-value-5678', $url);
    }

    public function test_upload_returns_422_when_s3_is_not_configured(): void
    {
        // Pin the env seed empty AND swap a fake adapter so no real
        // adapter is ever built (the dev .env may carry live credentials
        // into the testing config despite the phpunit.xml neutralization).
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->swapMediaStorageWithFake();
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'disabled or not configured'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_upload_returns_422_when_s3_is_disabled_but_configured(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'disabled or not configured'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_referenced_media_cannot_be_deleted(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $media = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/x.jpg',
            'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => $this->admin()->id,
        ]);

        Story::create([
            'type' => 'image',
            'media_file_id' => $media->id,
            'scheduled_at' => now()->addDay(),
            'connection_revision' => 1,
        ]);

        $this->deleteJson(route('media.destroy', $media))
            ->assertConflict();

        $this->assertDatabaseHas('media_files', ['id' => $media->id]);

        // Unreferenced media can be removed.
        $orphan = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/y.jpg',
            'original_name' => 'y.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'created_by' => $this->admin()->id,
        ]);

        $this->deleteJson(route('media.destroy', $orphan))->assertOk();
        $this->assertDatabaseMissing('media_files', ['id' => $orphan->id]);
    }

    public function test_destroy_deletes_s3_media_through_media_storage(): void
    {
        $this->enableS3Storage();
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('delete')->once()->with('stories/2026/11/x.jpg')->andReturn(true);
        $this->app->instance(MediaStorage::class, new MediaStorage(fn () => $disk));
        $this->actingAs($this->admin());

        $media = MediaFile::create([
            'disk' => 's3',
            'path' => 'stories/2026/11/x.jpg',
            'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $this->deleteJson(route('media.destroy', $media))->assertOk();
        $this->assertDatabaseMissing('media_files', ['id' => $media->id]);
    }

    public function test_destroy_refuses_s3_media_while_storage_is_disabled(): void
    {
        $this->swapMediaStorageWithFake();
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->actingAs($this->admin());

        $media = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => $this->admin()->id,
        ]);

        $this->deleteJson(route('media.destroy', $media))->assertConflict();
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    /**
     * The other half of the disable gate: credentials missing AND the toggle
     * off must produce the same 409 instead of an uncaught storage
     * exception (spec D3).
     */
    public function test_destroy_refuses_s3_media_while_storage_is_disabled_with_blank_credentials(): void
    {
        $this->swapMediaStorageWithFake();
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => null, 'secret' => null, 'bucket' => null,
        ])->save();
        $this->actingAs($this->admin());

        $media = MediaFile::create([
            'disk' => 's3', 'path' => 'stories/2026/11/x.jpg', 'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_by' => $this->admin()->id,
        ]);

        $this->deleteJson(route('media.destroy', $media))->assertConflict();
        $this->assertDatabaseHas('media_files', ['id' => $media->id]);
    }

    public function test_orphan_cleanup_removes_only_old_unreferenced_media(): void
    {
        Storage::fake('public');
        $user = $this->admin();

        Storage::disk('public')->put('stories/2026/10/old.jpg', 'old');
        Storage::disk('public')->put('stories/2026/10/fresh.jpg', 'fresh');
        Storage::disk('public')->put('stories/2026/10/ref.jpg', 'ref');

        $make = fn (string $path) => MediaFile::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => basename($path),
            'mime_type' => 'image/jpeg',
            'size_bytes' => 10,
            'created_by' => $user->id,
        ]);

        $oldOrphan = $make('stories/2026/10/old.jpg');
        $oldOrphan->forceFill(['created_at' => now()->subDays(2)])->save();

        $freshOrphan = $make('stories/2026/10/fresh.jpg');

        $referenced = $make('stories/2026/10/ref.jpg');
        $referenced->forceFill(['created_at' => now()->subDays(2)])->save();
        Story::create([
            'type' => 'image',
            'media_file_id' => $referenced->id,
            'scheduled_at' => now()->addDay(),
            'connection_revision' => 1,
        ]);

        $this->artisan('wahabuddy:prune-orphan-media')->assertSuccessful();

        $this->assertDatabaseMissing('media_files', ['id' => $oldOrphan->id]);
        Storage::disk('public')->assertMissing('stories/2026/10/old.jpg');

        $this->assertDatabaseHas('media_files', ['id' => $freshOrphan->id]);
        $this->assertDatabaseHas('media_files', ['id' => $referenced->id]);
        Storage::disk('public')->assertExists('stories/2026/10/ref.jpg');
    }

    public function test_media_url_resolution_local_and_signed(): void
    {
        config([
            'app.url' => 'https://app.example.test',
            'filesystems.disks.public.url' => 'https://app.example.test/storage',
        ]);

        // Legacy rows from the removed local-disk mode stay previewable via
        // the app URL, but are no longer sendable — a remote WAHA could not
        // fetch them, so the resolver must refuse instead of handing WAHA a
        // dead URL.
        $local = MediaFile::create([
            'disk' => 'public',
            'path' => 'stories/2026/10/pic.jpg',
            'original_name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $this->assertNull(app(MediaUrlResolver::class)->forSend($local));
        $this->assertSame(
            'https://app.example.test/storage/stories/2026/10/pic.jpg',
            app(MediaUrlResolver::class)->forPreview($local),
        );

        // S3-style private disk resolves to a time-limited signed URL through
        // the storage row; the presigned URL never embeds credentials.
        $this->enableS3Storage();

        $remote = MediaFile::create([
            'disk' => 's3',
            'path' => 'stories/2026/10/remote.jpg',
            'original_name' => 'remote.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $signed = app(MediaUrlResolver::class)->forSend($remote);

        // Fake disk signs via expiration; real S3/R2 SDKs use X-Amz-Signature.
        // Either way the storage credentials must never appear in the URL.
        $this->assertTrue(
            str_contains($signed, 'X-Amz-Signature') || str_contains($signed, 'expiration='),
            'Expected a signed URL.',
        );
        $this->assertStringNotContainsString('AKIA-PROBE-KEY-1234', $signed);
        $this->assertStringNotContainsString('probe-secret-value-5678', $signed);
    }

    public function test_send_and_preview_urls_are_null_when_storage_is_disabled(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => false, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        $this->swapMediaStorageWithFake();

        $media = MediaFile::create([
            'disk' => 's3',
            'path' => 'stories/2026/11/pic.jpg',
            'original_name' => 'pic.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $resolver = app(MediaUrlResolver::class);

        $this->assertNull($resolver->forSend($media), 'Disabled storage must not hand WAHA a URL (held instead).');
        $this->assertNull($resolver->forPreview($media), 'UI renders a placeholder instead of a broken URL.');
    }

    public function test_upload_returns_422_when_storage_write_fails(): void
    {
        S3Connection::singleton()->forceFill([
            'enabled' => true, 'key' => 'k', 'secret' => 's', 'bucket' => 'b',
        ])->save();
        Storage::fake('s3');
        $disk = $this->mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->andReturn(false);
        $this->app->instance(
            MediaStorage::class,
            new MediaStorage(fn () => $disk),
        );
        $this->actingAs($this->admin());

        $this->postJson(route('media.store'), ['file' => $this->jpeg()])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Storing the upload failed'));

        $this->assertDatabaseCount('media_files', 0);
    }

    public function test_old_storage_probe_route_is_removed(): void
    {
        $this->actingAs($this->admin());

        // No POST route answers the old probe URL anymore: the URI only
        // matches the DELETE media/{media} route, so Laravel answers 405
        // (method not allowed) — the probe endpoint itself is gone.
        $this->postJson('/media/test-storage')->assertMethodNotAllowed();
    }
}
