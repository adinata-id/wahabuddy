<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateStorageSettingsRequest;
use App\Models\AppSetting;
use App\Models\S3Connection;
use App\Services\Media\MediaStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * Storage card actions: saving the S3/R2 connection settings, clearing the
 * stored secret, and the sent-media cleanup ("Run cleanup now").
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

    public function update(UpdateStorageSettingsRequest $request, MediaStorage $storage): RedirectResponse
    {
        $row = $storage->row();
        $previousHash = $this->safeConfigHash($row);

        $input = $request->validated();

        // Stored-value fallbacks must never throw on unreadable ciphertext
        // (APP_KEY rotation): this form is exactly how the admin recovers
        // (spec section 4, row 8 — "tidak 500").
        $row->fill([
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $row->enabled,
            'key' => array_key_exists('key', $input) ? ($input['key'] !== null && $input['key'] !== '' ? $input['key'] : null) : $this->plainAttribute($row, 'key'),
            // Blank/omitted secret keeps the stored value; removal happens
            // only through the explicit clear-secret action.
            'secret' => filled($input['secret'] ?? null) ? $input['secret'] : $this->plainAttribute($row, 'secret'),
            'bucket' => array_key_exists('bucket', $input) ? ($input['bucket'] !== null && $input['bucket'] !== '' ? $input['bucket'] : null) : $this->plainAttribute($row, 'bucket'),
            'region' => filled($input['region'] ?? null) ? $input['region'] : 'auto',
            'endpoint' => array_key_exists('endpoint', $input) ? ($input['endpoint'] !== null && $input['endpoint'] !== '' ? $input['endpoint'] : null) : $this->plainAttribute($row, 'endpoint'),
            'use_path_style_endpoint' => array_key_exists('use_path_style_endpoint', $input) ? (bool) $input['use_path_style_endpoint'] : $row->use_path_style_endpoint,
        ]);

        // A changed effective config invalidates the stored test result
        // (spec 3.3): the banner must never describe other credentials.
        if ($previousHash !== null && $previousHash !== $this->safeConfigHash($row)) {
            $row->last_test_at = null;
            $row->last_test_status = null;
            $row->last_test_error = null;
        }

        $row->save();

        return back()->with('status', 'storage-updated');
    }

    public function clearSecret(MediaStorage $storage): RedirectResponse
    {
        $storage->row()->forceFill(['secret' => null])->save();

        return back()->with('status', 'storage-secret-cleared');
    }

    /**
     * Encrypted-cast read that degrades to null when the ciphertext is
     * unreadable instead of throwing.
     */
    private function plainAttribute(S3Connection $row, string $attribute): ?string
    {
        try {
            return $row->{$attribute};
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * configHash only when the current ciphertext is readable.
     */
    private function safeConfigHash(S3Connection $row): ?string
    {
        try {
            return $row->configHash();
        } catch (DecryptException) {
            return null;
        }
    }
}
