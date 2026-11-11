<?php

namespace App\Services\Media;

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
     * purged, its disk can no longer be resolved, or it lives on a disk
     * without presigned URLs (legacy local-disk rows) — the caller must
     * fail the send explicitly instead of handing WAHA a dead URL.
     */
    public function forSend(MediaFile $media): ?string
    {
        if ($media->purged_at !== null) {
            return null;
        }

        if (! $this->diskSupportsTemporaryUrls($media->disk)) {
            return null;
        }

        try {
            return Storage::disk($media->disk)
                ->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        } catch (\Throwable) {
            return null;
        }
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
