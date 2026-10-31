<?php

namespace App\Models;

use App\Enums\BroadcastStatus;
use App\Enums\BroadcastType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BroadcastType $type
 * @property BroadcastStatus $status
 * @property MediaFile|null $mediaFile
 * @property-read Collection<int, BroadcastRecipient> $recipients
 * @property-read Collection<int, BroadcastLog> $logs
 */
class Broadcast extends Model
{
    protected $fillable = [
        'name',
        'type',
        'media_file_id',
        'template_text',
        'status',
        'connection_revision',
        'next_send_at',
        'batch_count',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BroadcastType::class,
            'status' => BroadcastStatus::class,
            'next_send_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'batch_count' => 'integer',
            'connection_revision' => 'integer',
        ];
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return HasMany<BroadcastRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /** @return HasMany<BroadcastLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(BroadcastLog::class);
    }
}
