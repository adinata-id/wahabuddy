<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Singleton row of operational preferences (one row per installation).
 *
 * @property string $display_timezone
 * @property int $broadcast_delay_min
 * @property int $broadcast_delay_max
 * @property int $broadcast_batch_size
 * @property int $broadcast_batch_pause
 * @property bool $media_cleanup_enabled
 * @property string $media_cleanup_frequency
 * @property int|null $media_cleanup_day
 * @property int|null $media_cleanup_interval_days
 * @property int $media_cleanup_retention_days
 * @property bool $media_cleanup_include_terminal
 * @property Carbon|null $media_cleanup_last_run_at
 * @property Carbon|null $media_cleanup_next_run_at
 */
class AppSetting extends Model
{
    protected $fillable = [
        'display_timezone',
        'broadcast_delay_min',
        'broadcast_delay_max',
        'broadcast_batch_size',
        'broadcast_batch_pause',
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
        ])->refresh();
    }
}
