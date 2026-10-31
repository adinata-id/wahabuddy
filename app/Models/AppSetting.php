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
    ];

    protected function casts(): array
    {
        return [
            'display_timezone' => 'string',
            'broadcast_delay_min' => 'integer',
            'broadcast_delay_max' => 'integer',
            'broadcast_batch_size' => 'integer',
            'broadcast_batch_pause' => 'integer',
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
