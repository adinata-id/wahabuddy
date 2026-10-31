<?php

namespace App\Models;

use App\Enums\SchedulerRunStatus;
use Illuminate\Database\Eloquent\Model;

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
