<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TestStorageSettingsRequest;
use App\Http\Requests\Settings\UpdateStorageSettingsRequest;
use App\Models\AppSetting;
use App\Models\S3Connection;
use App\Models\S3Connection as S3ConnectionModel;
use App\Services\Media\MediaStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        // Saving over unreadable ciphertext (APP_KEY rotation) must not 500
        // when the dirty diff decrypts the corrupted original: drop the
        // unreadable encrypted originals so fresh values persist — this
        // form is exactly how the admin recovers (spec section 4, row 8 —
        // "tidak 500").
        foreach (['key', 'secret'] as $attribute) {
            if ($row->getRawOriginal($attribute) !== null && $this->plainAttribute($row, $attribute) === null) {
                $row->setRawAttributes([...$row->getAttributes(), $attribute => null], true);
            }
        }

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
        // A save that recovers from unreadable ciphertext also invalidates:
        // the previous hash was unknowable, so a surviving banner could
        // describe entirely different credentials.
        $hashAfterSave = $this->safeConfigHash($row);
        if ($hashAfterSave !== null && $previousHash !== $hashAfterSave) {
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
     * Probe possibly-unsaved form values: write a tiny object through an
     * ephemeral adapter, fetch it, always clean up (spec D2). last_test_* is
     * stored — passed OR failed — only when the tested config matches the
     * saved row (gated on hash equality, not on the outcome; spec 3.3).
     */
    public function test(TestStorageSettingsRequest $request, MediaStorage $storage): JsonResponse
    {
        $input = $request->validated();
        $row = $storage->row();

        $diskConfig = array_filter([
            'driver' => 's3',
            'key' => $input['key'] ?? null,
            'secret' => filled($input['secret'] ?? null) ? $input['secret'] : $this->plainAttribute($row, 'secret'),
            'bucket' => $input['bucket'] ?? null,
            'region' => filled($input['region'] ?? null) ? $input['region'] : 'auto',
            'endpoint' => filled($input['endpoint'] ?? null) ? $input['endpoint'] : null,
            'use_path_style_endpoint' => (bool) ($input['use_path_style_endpoint'] ?? true),
            'throw' => false,
        ], fn ($value): bool => $value !== null);

        $path = 'stories/probe-'.Str::uuid().'.txt';

        try {
            $adapter = $storage->buildFor($diskConfig);

            if ($adapter->put($path, 'wahabuddy storage probe') === false) {
                return $this->probeResult($row, false, null, $diskConfig, 'write_failed', 'Writing the probe object to the bucket failed (write_failed).');
            }

            // The fetch URL comes from the SAME ephemeral adapter — never
            // via MediaUrlResolver, which gates on the saved row.
            $url = $adapter->temporaryUrl($path, now()->addMinutes(5));
            $response = Http::timeout(5)->withoutRedirecting()->get($url);

            if (! $response->successful()) {
                return $this->probeResult($row, false, $response->status(), $diskConfig, 'fetch_failed', 'The bucket answered with an error status (fetch_failed).');
            }

            if (trim($response->body()) !== 'wahabuddy storage probe') {
                return $this->probeResult($row, false, $response->status(), $diskConfig, 'content_mismatch', 'The bucket served unexpected bytes for the probe object (content_mismatch).');
            }

            return $this->probeResult($row, true, $response->status(), $diskConfig, null, 'The bucket answered with the probe bytes.');
        } catch (\Throwable) {
            return $this->probeResult($row, false, null, $diskConfig, 'fetch_failed', 'The probe object could not be fetched from the bucket (fetch_failed).');
        } finally {
            try {
                $storage->buildFor($diskConfig)->delete($path);
            } catch (\Throwable) {
                // Best effort; the probe object is tiny.
            }
        }
    }

    /**
     * Static note strings only — never interpolate exception messages, URLs,
     * or query strings (presign signatures) into last_test_error (spec 3.3).
     *
     * @param  array<string, mixed>  $diskConfig
     */
    private function probeResult(
        S3ConnectionModel $row,
        bool $reachable,
        ?int $status,
        array $diskConfig,
        ?string $errorCode,
        string $note,
    ): JsonResponse {
        $matchesSaved = S3ConnectionModel::configHashFor($diskConfig) === $this->safeConfigHash($row);

        if ($matchesSaved) {
            $row->forceFill([
                'last_test_at' => now(),
                'last_test_status' => $reachable ? 'passed' : 'failed',
                'last_test_error' => $errorCode,
            ])->save();
        }

        return response()->json([
            'reachable' => $reachable,
            'status' => $status,
            'persisted' => $matchesSaved,
            'note' => $note,
        ]);
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
