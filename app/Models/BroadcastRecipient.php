<?php

namespace App\Models;

use App\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RecipientStatus $status
 * @property Broadcast $broadcast
 */
class BroadcastRecipient extends Model
{
    protected $fillable = [
        'broadcast_id',
        'chat_id',
        'name',
        'rendered_text',
        'status',
        'attempts',
        'attempt_token',
        'sending_started_at',
        'next_attempt_at',
        'waha_message_id',
        'waha_key_id',
        'last_error_code',
        'last_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'sending_started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }
}
