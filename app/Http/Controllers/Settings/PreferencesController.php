<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePreferencesRequest;
use App\Models\AppSetting;
use App\Services\Media\SentMediaCleanup;
use Illuminate\Http\RedirectResponse;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request, SentMediaCleanup $cleanup): RedirectResponse
    {
        $settings = AppSetting::singleton();
        $validated = $request->validated();

        $settings->fill($validated)->save();

        // Saving any cleanup setting reschedules immediately: a cadence
        // change must never wait out the previous interval. A capped run's
        // drain slot is never pushed further out — the sooner of the drain
        // time and the new cadence slot wins.
        $cleanupTouched = collect($validated)->keys()
            ->contains(fn (string $key): bool => str_starts_with($key, 'media_cleanup_'));

        if ($cleanupTouched) {
            $target = $settings->media_cleanup_enabled
                ? $cleanup->nextRunAt(now()->addMinute(), $settings)
                : null;

            $current = $settings->media_cleanup_next_run_at;

            // Only a still-future drain slot is worth keeping; a past or
            // missing value must not survive the reschedule.
            if ($target !== null && $current !== null && $current->isFuture() && $current->lt($target)) {
                $target = $current;
            }

            $settings->fill(['media_cleanup_next_run_at' => $target])->save();
        }

        return back()->with('status', 'preferences-updated');
    }
}
