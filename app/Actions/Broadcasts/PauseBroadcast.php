<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Models\Broadcast;

/**
 * Pause stops future sends but never wipes the queue: jobs that wake while
 * paused are no-ops, and in-flight requests may still complete (spec I).
 */
final class PauseBroadcast
{
    public function handle(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [BroadcastStatus::Queued, BroadcastStatus::Running], true)) {
            throw new \InvalidArgumentException('Only queued or running campaigns can be paused.');
        }

        Broadcast::query()
            ->whereKey($broadcast->id)
            ->whereIn('status', [BroadcastStatus::Queued->value, BroadcastStatus::Running->value])
            ->update(['status' => BroadcastStatus::Paused->value]);

        $broadcast->refresh();
        $broadcast->logs()->create([
            'event' => 'paused',
            'message' => 'Campaign paused by admin. Already-running requests may still complete.',
        ]);
    }
}
