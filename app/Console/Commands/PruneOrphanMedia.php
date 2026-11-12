<?php

namespace App\Console\Commands;

use App\Models\Broadcast;
use App\Models\MediaFile;
use App\Models\Story;
use App\Services\Media\MediaStorage;
use Illuminate\Console\Command;

/**
 * Removes uploaded media older than 24 hours that no story or broadcast
 * references anymore (spec H.5). Media in use by unknown/sending work is
 * referenced and therefore always kept.
 */
class PruneOrphanMedia extends Command
{
    protected $signature = 'wahabuddy:prune-orphan-media {--hours=24 : Minimum age before an upload counts as orphaned}';

    protected $description = 'Delete orphaned media uploads older than the given age';

    public function handle(MediaStorage $storage): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $storyMedia = Story::whereNotNull('media_file_id')->pluck('media_file_id');
        $broadcastMedia = Broadcast::whereNotNull('media_file_id')->pluck('media_file_id');
        $referenced = $storyMedia->merge($broadcastMedia)->flip();

        $removed = 0;

        MediaFile::where('created_at', '<', now()->subHours($hours))
            ->whereNotIn('id', $referenced->keys()->all())
            ->chunkById(100, function ($chunk) use ($storage, &$removed) {
                foreach ($chunk as $media) {
                    // Disable semantics are per-row (spec D3): skip the bytes
                    // AND the row so the next weekly run retries once storage
                    // is re-enabled (spec 3.5).
                    if ($media->disk === 's3' && ! $storage->configured()) {
                        continue;
                    }

                    $storage->diskFor($media->disk)->delete($media->path);
                    $media->delete();
                    $removed++;
                }
            });

        $this->info("Removed {$removed} orphaned media file(s).");

        return self::SUCCESS;
    }
}
