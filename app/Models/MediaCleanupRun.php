<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per sent-media cleanup execution. The table is pruned to the
 * newest self::MAX_ROWS rows after every run.
 *
 * @property int $id
 * @property string $trigger
 * @property string $status
 * @property int $files_purged
 * @property int $bytes_freed
 * @property int $files_failed
 * @property bool $capped
 * @property string|null $message
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class MediaCleanupRun extends Model
{
    public const MAX_ROWS = 100;

    protected $fillable = [
        'trigger',
        'status',
        'files_purged',
        'bytes_freed',
        'files_failed',
        'capped',
        'message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'files_purged' => 'integer',
            'bytes_freed' => 'integer',
            'files_failed' => 'integer',
            'capped' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Keep only the newest self::MAX_ROWS rows.
     */
    public static function pruneToLimit(): void
    {
        $staleIds = static::query()
            ->orderByDesc('id')
            ->pluck('id')
            ->skip(self::MAX_ROWS);

        if ($staleIds->isNotEmpty()) {
            static::query()->whereIn('id', $staleIds->all())->delete();
        }
    }

    public static function start(string $trigger): self
    {
        return static::create([
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function finish(int $filesPurged, int $bytesFreed, int $filesFailed, bool $capped, string $message): void
    {
        $this->fill([
            'status' => 'completed',
            'files_purged' => $filesPurged,
            'bytes_freed' => $bytesFreed,
            'files_failed' => $filesFailed,
            'capped' => $capped,
            'message' => $message,
            'finished_at' => now(),
        ])->save();
    }
}
