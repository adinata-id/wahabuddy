<?php

namespace Tests\Feature;

use App\Models\S3Connection;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStorageException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private function enabledRow(): S3Connection
    {
        return S3Connection::singleton()->forceFill([
            'enabled' => true,
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
        ])->save()
            ? S3Connection::singleton()->refresh()
            : S3Connection::singleton()->refresh();
    }

    public function test_configured_requires_enabled_and_complete_credentials(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]); // no env seed

        $storage = app(MediaStorage::class);

        $this->assertFalse($storage->configured(), 'No row yet with empty env → not configured.');

        S3Connection::singleton()->forceFill(['key' => 'k', 'secret' => 's', 'bucket' => 'b'])->save();
        $this->assertFalse($storage->configured(), 'Complete credentials but disabled → not configured.');

        $this->enabledRow();
        $this->assertTrue($storage->configured());
    }

    public function test_disk_for_s3_reuses_adapter_until_config_changes(): void
    {
        $storage = app(MediaStorage::class);
        $row = $this->enabledRow();

        $first = $storage->diskFor('s3');
        $second = $storage->diskFor('s3');
        $this->assertSame($first, $second, 'Same config hash must reuse the cached adapter.');

        $row->forceFill(['secret' => 'rotated'])->save();
        $third = $storage->diskFor('s3');
        $this->assertNotSame($first, $third, 'A config change must build a fresh adapter (no worker restart).');
    }

    public function test_disk_for_public_is_a_passthrough(): void
    {
        $this->assertSame(
            Storage::disk('public'),
            app(MediaStorage::class)->diskFor('public'),
        );
    }

    public function test_disk_for_s3_throws_when_not_configured(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3']]); // no env seed

        $this->expectException(MediaStorageException::class);
        app(MediaStorage::class)->diskFor('s3');
    }

    public function test_undecryptable_credentials_degrade_to_not_configured(): void
    {
        $this->enabledRow();
        // Simulate APP_KEY rotation: ciphertext no longer decrypts.
        DB::table('s3_connections')->update(['secret' => 'not-valid-ciphertext']);

        $storage = app(MediaStorage::class);

        $this->assertTrue($storage->credentialsUnreadable());
        $this->assertFalse($storage->configured(), 'Unreadable credentials must not 500 the hot path.');
    }
}
