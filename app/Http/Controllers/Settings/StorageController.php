<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Manual triggers for the storage card: the sent-media cleanup ("Run
 * cleanup now") and the public media URL probe ("Check public URL").
 */
class StorageController extends Controller
{
    /**
     * Fetch the operator's public media base once from the backend so a
     * dead tunnel is caught in Settings instead of as failed sends.
     */
    public function checkPublicUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url:http,https', 'max:255'],
        ]);

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get(rtrim($validated['base_url'], '/'));

            return response()->json([
                'reachable' => $response->successful(),
                'status' => $response->status(),
                'note' => $response->successful()
                    ? 'The URL answered from this backend.'
                    : 'The URL answered with an error status.',
            ]);
        } catch (\Throwable) {
            return response()->json([
                'reachable' => false,
                'status' => null,
                'note' => 'The URL could not be reached (DNS, tunnel down, or timeout).',
            ]);
        }
    }

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
