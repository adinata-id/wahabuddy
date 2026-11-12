<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Singleton row describing the S3-compatible storage of this installation.
 *
 * Credentials use encrypted casts and must never be serialized to the
 * browser — the settings UI only receives has_* flags plus a masked hint
 * (mirror of WahaConnection).
 *
 * @property bool $enabled
 * @property string|null $key
 * @property string|null $secret
 * @property string|null $bucket
 * @property string $region
 * @property string|null $endpoint
 * @property bool $use_path_style_endpoint
 * @property Carbon|null $last_test_at
 * @property string|null $last_test_status
 * @property string|null $last_test_error
 */
class S3Connection extends Model
{
    protected $fillable = [
        'enabled',
        'key',
        'secret',
        'bucket',
        'region',
        'endpoint',
        'use_path_style_endpoint',
        'last_test_at',
        'last_test_status',
        'last_test_error',
    ];

    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
            'use_path_style_endpoint' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    /**
     * The single storage row. The first creation seeds itself from the env
     * AWS_* disk config (enabled only when complete); afterwards the
     * database is authoritative and env changes are ignored (spec D1).
     */
    public static function singleton(): self
    {
        try {
            return static::query()->firstOrCreate([], self::seedFromEnv());
        } catch (UniqueConstraintViolationException) {
            // A concurrent process won the creation race.
            return static::query()->firstOrFail();
        }
    }

    public function isConfigured(): bool
    {
        return filled($this->key) && filled($this->secret) && filled($this->bucket);
    }

    /**
     * Laravel filesystem disk config for this row. Null fields are omitted;
     * region always resolves (R2 uses "auto").
     *
     * @return array<string, mixed>
     */
    public function toDiskConfig(): array
    {
        return array_filter([
            'driver' => 's3',
            'key' => $this->key,
            'secret' => $this->secret,
            'bucket' => $this->bucket,
            'region' => $this->region ?? 'auto',
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => $this->use_path_style_endpoint,
            'throw' => false,
        ], fn ($value): bool => $value !== null);
    }

    public function configHash(): string
    {
        return self::configHashFor($this->toDiskConfig());
    }

    /**
     * Hash over exactly the connection fields (key, secret, bucket, region,
     * endpoint, use_path_style_endpoint). driver/throw and key order are
     * excluded; enabled and last_test_* never participate (spec 3.1).
     *
     * @param  array<string, mixed>  $diskConfig
     */
    public static function configHashFor(array $diskConfig): string
    {
        $fields = [];
        foreach (['key', 'secret', 'bucket', 'region', 'endpoint', 'use_path_style_endpoint'] as $field) {
            $fields[$field] = $diskConfig[$field] ?? null;
        }
        ksort($fields);

        return hash('sha256', serialize($fields));
    }

    /** @return array<string, mixed> */
    private static function seedFromEnv(): array
    {
        $disk = config('filesystems.disks.s3');

        return [
            'enabled' => filled($disk['key'] ?? null)
                && filled($disk['secret'] ?? null)
                && filled($disk['bucket'] ?? null),
            // Blank .env values arrive as '' (env() never yields null for
            // set-but-empty vars) — seed them as null so has_*/isConfigured
            // never see phantom credentials.
            'key' => filled($disk['key'] ?? null) ? $disk['key'] : null,
            'secret' => filled($disk['secret'] ?? null) ? $disk['secret'] : null,
            'bucket' => filled($disk['bucket'] ?? null) ? $disk['bucket'] : null,
            'region' => filled($disk['region'] ?? null) ? $disk['region'] : 'auto',
            'endpoint' => filled($disk['endpoint'] ?? null) ? $disk['endpoint'] : null,
            'use_path_style_endpoint' => (bool) ($disk['use_path_style_endpoint'] ?? true),
        ];
    }
}
