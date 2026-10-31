<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row describing the one active WAHA connection of this installation.
 *
 * Secrets (api_key, basic_password) use encrypted casts; they must never be
 * serialized to the browser — the settings UI only receives has_* flags plus a
 * masked hint.
 *
 * @property string|null $base_url
 * @property string|null $api_key
 * @property string|null $basic_username
 * @property string|null $basic_password
 * @property string $session
 * @property int $revision
 * @property array<string, mixed>|null $capabilities
 * @property Carbon|null $last_test_at
 * @property string|null $last_test_status
 * @property string|null $last_test_error
 */
class WahaConnection extends Model
{
    /**
     * Application-level defaults mirroring the migration defaults, so freshly
     * created singletons carry them on the model instance too.
     */
    protected $attributes = [
        'session' => 'default',
        'revision' => 1,
    ];

    protected $fillable = [
        'base_url',
        'api_key',
        'basic_username',
        'basic_password',
        'session',
        'revision',
        'capabilities',
        'last_test_at',
        'last_test_status',
        'last_test_error',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'basic_password' => 'encrypted',
            'capabilities' => 'array',
            'last_test_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /**
     * The single connection row, created on first use.
     */
    public static function singleton(): self
    {
        return static::query()->firstOr(function (): self {
            return static::create(['session' => 'default']);
        });
    }

    public function isConfigured(): bool
    {
        return filled($this->base_url);
    }
}
