<?php

namespace App\Models;

use App\Enums\SendAttemptOutcome;
use App\Enums\SendAttemptSubject;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per actually-started HTTP send: the durable proof that decides
 * recovery semantics when responses or workers are lost (spec F/G).
 */
class SendAttempt extends Model
{
    protected $fillable = [
        'attempt_token',
        'subject_type',
        'subject_id',
        'connection_revision',
        'started_at',
        'finished_at',
        'outcome',
        'http_status',
        'message_id',
        'key_id',
        'error_code',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => SendAttemptSubject::class,
            'outcome' => SendAttemptOutcome::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'http_status' => 'integer',
            'connection_revision' => 'integer',
        ];
    }
}
