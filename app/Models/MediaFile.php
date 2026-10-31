<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uploaded media intended for stories/broadcasts. The public URL is never
 * stored as truth — it is derived from disk/path per send (spec F/H).
 */
class MediaFile extends Model
{
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'video_codec',
        'created_by',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_by' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mime_type, 'video/');
    }
}
