<?php

namespace Tests\Feature;

use App\Models\S3Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class S3ConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_singleton_from_env_config_and_enables_when_complete(): void
    {
        config(['filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'env-key',
            'secret' => 'env-secret',
            'bucket' => 'env-bucket',
            'region' => null, // AWS_DEFAULT_REGION absent in many installs
            'endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
        ]]);

        $row = S3Connection::singleton();

        $this->assertTrue($row->enabled);
        $this->assertSame('env-key', $row->key);
        $this->assertSame('env-secret', $row->secret);
        $this->assertSame('env-bucket', $row->bucket);
        $this->assertSame('auto', $row->region, 'Empty region must seed as auto (R2).');
        $this->assertSame('https://acct.r2.cloudflarestorage.com', $row->endpoint);
        $this->assertTrue((bool) $row->use_path_style_endpoint);
    }

    public function test_seeds_disabled_when_env_credentials_incomplete(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'key' => 'env-key']]);

        $row = S3Connection::singleton();

        $this->assertFalse($row->enabled);
        $this->assertFalse($row->isConfigured());
    }

    public function test_existing_row_is_never_reseeded_from_env(): void
    {
        $row = S3Connection::singleton();
        $row->forceFill(['key' => null, 'secret' => null, 'bucket' => null])->save();

        config(['filesystems.disks.s3.key' => 'later-env-key']);
        $row = S3Connection::singleton();

        $this->assertNull($row->key, 'DB wins once the row exists — env must not refill cleared values (spec D1).');
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $row = S3Connection::singleton();
        $row->forceFill(['key' => 'plain-key', 'secret' => 'plain-secret'])->save();

        $this->assertSame('plain-key', $row->refresh()->key);
        $this->assertSame('plain-secret', $row->refresh()->secret);

        $raw = DB::table('s3_connections')->first();
        $this->assertStringNotContainsString('plain-key', (string) $raw->key);
        $this->assertStringNotContainsString('plain-secret', (string) $raw->secret);
    }

    public function test_config_hash_covers_connection_fields_only(): void
    {
        $a = S3Connection::configHashFor(['driver' => 's3', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'region' => 'auto', 'endpoint' => null, 'use_path_style_endpoint' => true, 'throw' => false]);
        $sameDifferentOrder = S3Connection::configHashFor(['throw' => false, 'endpoint' => null, 'use_path_style_endpoint' => true, 'region' => 'auto', 'bucket' => 'b', 'secret' => 's', 'key' => 'k', 'driver' => 's3']);
        $newSecret = S3Connection::configHashFor(['driver' => 's3', 'key' => 'k', 'secret' => 'OTHER', 'bucket' => 'b', 'region' => 'auto', 'endpoint' => null, 'use_path_style_endpoint' => true, 'throw' => false]);

        $this->assertSame($a, $sameDifferentOrder, 'driver/throw and key order must not matter.');
        $this->assertNotSame($a, $newSecret, 'A changed secret must change the hash.');
    }

    public function test_singleton_guard_rejects_a_second_row(): void
    {
        S3Connection::singleton();

        $this->expectException(UniqueConstraintViolationException::class);
        S3Connection::create(['enabled' => true]);
    }
}
