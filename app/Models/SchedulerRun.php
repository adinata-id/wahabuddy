<?php

namespace App\Models;

use App\Enums\SchedulerRunStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \App\Enums\SchedulerRunStatus $status
 * @property int $processed_count
 * @property int $error_count
 * @property string|null $last_error
 */
class SchedulerRun extends Model
{
    protected $fillable = [
        'started_at',
        'finished_at',
        'status',
        'processed_count',
        'error_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => SchedulerRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'processed_count' => 'integer',
            'error_count' => 'integer',
        ];
    }
}
