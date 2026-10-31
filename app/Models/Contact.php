<?php

namespace App\Models;

use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $connection_revision
 * @property string $chat_id
 * @property string|null $name
 * @property ContactType $type
 * @property Carbon|null $opted_in_at
 * @property Carbon|null $opted_out_at
 */
class Contact extends Model
{
    protected $fillable = [
        'connection_revision',
        'chat_id',
        'name',
        'type',
        'opted_in_at',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'connection_revision' => 'integer',
            'opted_in_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    public function isOptedIn(): bool
    {
        return $this->opted_in_at !== null && $this->opted_out_at === null;
    }
}
