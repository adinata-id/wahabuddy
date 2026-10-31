<?php

namespace App\Services\Broadcasts;

use App\Models\AppSetting;

/**
 * Produces pacing delays: uniform random integer in [min, max] seconds,
 * inclusive bounds, read fresh from settings each time (spec I).
 */
final class PacingClock
{
    public function randomDelaySeconds(?int $min = null, ?int $max = null): int
    {
        $settings = AppSetting::singleton();

        $min = max(1, $min ?? $settings->broadcast_delay_min);
        $max = max($min, $max ?? $settings->broadcast_delay_max);

        return random_int($min, $max);
    }
}
