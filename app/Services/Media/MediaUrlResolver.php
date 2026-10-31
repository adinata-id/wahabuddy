<?php

namespace App\Services\Media;

use App\Models\AppSetting;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;

/**
 * Derives fresh, send-ready URLs for media. URLs are never persisted or
 * logged; private disks produce short-lived presigned URLs (spec H.3).
 */
final class MediaUrlResolver
{
    private const SIGNED_URL_TTL_MINUTES = 15;

    /**
     * Fresh URL for the send pipeline. Null when the stored object was
     * purged or its disk can no longer be resolved — the caller must not
     * hand WAHA a URL to deleted bytes.
     */
    public function forSend(MediaFile $media): ?string
    {
        if ($media->purged_at !== null) {
            return null;
        }

        try {
            $disk = Storage::disk($media->disk);
        } catch (\Throwable) {
            return null;
        }

        if ($this->diskSupportsTemporaryUrls($media->disk)) {
            return $disk->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        // Local media is unreachable for a remote WAHA, so send URLs may be
        // pointed at a public base (e.g. a tunnel) from Settings. Previews
        // keep the app URL — the admin's browser can always reach it.
        $publicBase = AppSetting::singleton()->media_public_base_url;

        if ($publicBase !== null && $publicBase !== '') {
            return rtrim((string) $publicBase, '/').'/'.$media->path;
        }

        return $disk->url($media->path);
    }

    /**
     * Long-lived preview URL for UI thumbnails (local public disk or bucket
     * custom domain); falls back to a temporary URL for private disks.
     * Returns null once the stored object was purged by cleanup (or its
     * disk cannot be resolved) — callers render a placeholder instead of a
     * broken URL.
     */
    public function forPreview(MediaFile $media): ?string
    {
        if ($media->purged_at !== null) {
            return null;
        }

        try {
            $disk = Storage::disk($media->disk);
        } catch (\Throwable) {
            return null;
        }

        if ($this->diskSupportsTemporaryUrls($media->disk)) {
            return $disk->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        return $disk->url($media->path);
    }

    private function diskSupportsTemporaryUrls(string $diskName): bool
    {
        $config = config("filesystems.disks.{$diskName}");

        // Only object-storage style drivers produce presigned URLs; the local
        // public disk is plain public access.
        return in_array($config['driver'] ?? 'local', ['s3'], true);
    }
}
