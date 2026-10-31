<?php

namespace App\Models;

use App\Enums\StoryStatus;
use App\Enums\StoryType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property StoryType $type
 * @property int|null $media_file_id
 * @property string|null $text
 * @property string $background_color
 * @property int $font
 * @property Carbon $scheduled_at
 * @property StoryStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sending_started_at
 * @property string|null $attempt_token
 * @property int $connection_revision
 * @property string|null $waha_message_id
 * @property string|null $waha_key_id
 * @property string|null $last_error_code
 * @property string|null $last_error
 * @property Carbon|null $sent_at
 * @property Carbon|null $deleted_from_waha_at
 * @property MediaFile|null $mediaFile
 * @property-read Collection<int, StoryLog> $logs
 */
class Story extends Model
{
    protected $fillable = [
        'type',
        'media_file_id',
        'text',
        'background_color',
        'font',
        'scheduled_at',
        'status',
        'attempts',
        'next_attempt_at',
        'sending_started_at',
        'attempt_token',
        'connection_revision',
        'waha_message_id',
        'waha_key_id',
        'last_error_code',
        'last_error',
        'sent_at',
        'deleted_from_waha_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StoryType::class,
            'status' => StoryStatus::class,
            'scheduled_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'deleted_from_waha_at' => 'datetime',
            'attempts' => 'integer',
            'font' => 'integer',
            'connection_revision' => 'integer',
            'media_file_id' => 'integer',
        ];
    }

    /** @return BelongsTo<MediaFile, $this> */
    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    /** @return HasMany<StoryLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(StoryLog::class);
    }

    /**
     * G1: rows that may still be edited (never sent successfully).
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [StoryStatus::Pending, StoryStatus::Failed], true)
            && $this->sent_at === null;
    }
}
