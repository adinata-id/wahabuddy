<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Jobs\ProcessBroadcastJob;
use App\Models\Broadcast;

/**
 * Resume is only valid from paused (spec I) — including campaigns paused by
 * an unknown outcome, and only after that outcome was resolved.
 */
final class ResumeBroadcast
{
    public function handle(Broadcast $broadcast): void
    {
        if ($broadcast->status !== BroadcastStatus::Paused) {
            throw new \InvalidArgumentException('Only paused campaigns can be resumed.');
        }

        Broadcast::query()
            ->whereKey($broadcast->id)
            ->where('status', BroadcastStatus::Paused->value)
            ->update([
                'status' => BroadcastStatus::Running->value,
                'next_send_at' => $broadcast->next_send_at?->max(now()) ?? now(),
            ]);

        $broadcast->refresh();
        $broadcast->logs()->create([
            'event' => 'resumed',
            'message' => 'Campaign resumed by admin.',
        ]);

        ProcessBroadcastJob::dispatch($broadcast->id);
    }
}
