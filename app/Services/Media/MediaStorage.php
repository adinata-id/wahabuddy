<?php

namespace App\Services\Media;

use App\Models\S3Connection;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Single door for media storage operations. The s3 adapter is built from
 * the s3_connections row via Storage::build() behind a STATIC per-process
 * cache keyed by config hash — instances are resolved per request/job, so
 * instance state would be discarded. row() re-reads the database on every
 * call, so new credentials apply on the next call without restarts (spec D4).
 */
final class MediaStorage
{
    /** @var array<string, FilesystemAdapter> */
    private static array $adapterCache = [];

    /**
     * @param  Closure(array<string, mixed>): FilesystemAdapter|null  $adapterFactory
     */
    public function __construct(private readonly ?Closure $adapterFactory = null) {}

    public function row(): S3Connection
    {
        return S3Connection::singleton();
    }

    public function configured(): bool
    {
        $row = $this->row();

        if (! $row->enabled) {
            return false;
        }

        try {
            return $row->isConfigured();
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * True when the stored ciphertext cannot be decrypted (e.g. APP_KEY
     * rotated). UI renders a "re-enter credentials" state (spec 3.1).
     */
    public function credentialsUnreadable(): bool
    {
        try {
            $this->row()->isConfigured();

            return false;
        } catch (DecryptException) {
            return true;
        }
    }

    public function diskFor(string $disk): FilesystemAdapter
    {
        if ($disk === 'public') {
            return Storage::disk('public');
        }

        if ($disk !== 's3') {
            throw new MediaStorageException("Unknown media disk [{$disk}].");
        }

        $config = $this->resolvedDiskConfig();

        // The test factory bypasses the static cache entirely so swapped
        // fakes never leak between tests in one process.
        if ($this->adapterFactory instanceof Closure) {
            return ($this->adapterFactory)($config);
        }

        $hash = S3Connection::configHashFor($config);

        return self::$adapterCache[$hash] ??= Storage::build($config);
    }

    /**
     * Ephemeral adapter for probing UNSAVED form values (spec D2). Never
     * cached, never gated on the saved row — but still honors the test
     * factory so probe tests never build real adapters.
     *
     * @param  array<string, mixed>  $diskConfig
     */
    public function buildFor(array $diskConfig): FilesystemAdapter
    {
        if ($this->adapterFactory instanceof Closure) {
            return ($this->adapterFactory)($diskConfig);
        }

        return Storage::build($diskConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedDiskConfig(): array
    {
        $row = $this->row();

        if (! $row->isConfigured()) {
            throw new MediaStorageException('S3 storage is not configured.');
        }

        return $row->toDiskConfig();
    }
}
