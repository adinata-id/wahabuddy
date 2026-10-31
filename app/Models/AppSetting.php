<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row of operational preferences (one row per installation).
 */
class AppSetting extends Model
{
    protected $fillable = [
        'display_timezone',
        'broadcast_delay_min',
        'broadcast_delay_max',
        'broadcast_batch_size',
        'broadcast_batch_pause',
        'media_disk',
        'media_public_base_url',
        'media_cleanup_enabled',
        'media_cleanup_frequency',
        'media_cleanup_day',
        'media_cleanup_interval_days',
        'media_cleanup_retention_days',
        'media_cleanup_include_terminal',
        'media_cleanup_last_run_at',
        'media_cleanup_next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'display_timezone' => 'string',
            'broadcast_delay_min' => 'integer',
            'broadcast_delay_max' => 'integer',
            'broadcast_batch_size' => 'integer',
            'broadcast_batch_pause' => 'integer',
            'media_cleanup_enabled' => 'boolean',
            'media_cleanup_day' => 'integer',
            'media_cleanup_interval_days' => 'integer',
            'media_cleanup_retention_days' => 'integer',
            'media_cleanup_include_terminal' => 'boolean',
            'media_cleanup_last_run_at' => 'datetime',
            'media_cleanup_next_run_at' => 'datetime',
        ];
    }

    /**
     * The single settings row, created on first use with env-driven defaults.
     * The instance is refreshed after creation so DB column defaults (pacing
     * values) are visible without another query.
     */
    public static function singleton(): self
    {
        $existing = static::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'display_timezone' => config('wahabuddy.display_timezone', 'Asia/Jakarta'),
            // MEDIA_DISK seeds the initial disk choice; afterwards the
            // setting is the source of truth (editable in the UI).
            'media_disk' => (string) config('wahabuddy.media_disk', 'public'),
        ])->refresh();
    }
}
