<?php

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Models\Broadcast;
use Illuminate\Support\Facades\DB;

/**
 * Cancel is terminal: recipients that never started are canceled; sends that
 * are already in flight may still complete and be recorded as sent (spec I).
 */
final class CancelBroadcast
{
    public function handle(Broadcast $broadcast): void
    {
        if (in_array($broadcast->status, [BroadcastStatus::Completed, BroadcastStatus::Canceled], true)) {
            throw new \InvalidArgumentException('This campaign already finished.');
        }

        DB::transaction(function () use ($broadcast): void {
            $canceled = $broadcast->recipients()
                ->where('status', RecipientStatus::Pending->value)
                ->update([
                    'status' => RecipientStatus::Canceled->value,
                    'next_attempt_at' => null,
                ]);

            $broadcast->forceFill([
                'status' => BroadcastStatus::Canceled,
                'finished_at' => now(),
            ])->save();

            $broadcast->logs()->create([
                'event' => 'canceled',
                'message' => "Campaign canceled by admin; {$canceled} not-yet-started recipient(s) canceled. In-flight requests may still complete.",
            ]);
        });
    }
}
