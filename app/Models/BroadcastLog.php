<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastLog extends Model
{
    protected $fillable = [
        'broadcast_id',
        'recipient_id',
        'event',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<Broadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /** @return BelongsTo<BroadcastRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(BroadcastRecipient::class);
    }
}
