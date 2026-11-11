<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Manual trigger for the storage card: the sent-media cleanup
 * ("Run cleanup now").
 */
class StorageController extends Controller
{
    public function runCleanup(): RedirectResponse
    {
        if (! AppSetting::singleton()->media_cleanup_enabled) {
            return back()->withErrors(['storage' => 'Auto-cleanup is disabled. Enable it first.']);
        }

        // Synchronous on purpose (local deletes are instant); capped tight
        // so a slow remote disk cannot drag the request into proxy
        // timeouts. Revisit with a queued dispatch once S3 is activated.
        Artisan::call('wahabuddy:prune-sent-media', ['--force' => true, '--max' => 50]);

        $summary = trim(Artisan::output());

        return back()
            ->with('status', 'storage-cleanup-run')
            ->with('cleanup_summary', $summary !== '' ? $summary : 'Cleanup did not report a result — another cleanup may be running; try again shortly.');
    }
}
