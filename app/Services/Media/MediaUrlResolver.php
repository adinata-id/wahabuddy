<?php

namespace App\Services\Media;

use App\Models\MediaFile;

/**
 * Derives fresh, send-ready URLs for media through MediaStorage. URLs are
 * never persisted or logged; the private s3 disk produces short-lived
 * presigned URLs (spec H.3) and disabled storage yields null instead of a
 * dead URL.
 */
final class MediaUrlResolver
{
    private const SIGNED_URL_TTL_MINUTES = 15;

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Fresh URL for the send pipeline. Null when the stored object was
     * purged, does not live on the s3 disk (legacy local-disk rows have no
     * URL a remote WAHA could fetch), or storage is disabled or not
     * configured — the caller must fail the send explicitly instead of
     * handing WAHA a dead URL.
     */
    public function forSend(MediaFile $media): ?string
    {
        if ($media->purged_at !== null || $media->disk !== 's3' || ! $this->storage->configured()) {
            return null;
        }

        try {
            return $this->storage->diskFor('s3')
                ->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Long-lived preview URL for UI thumbnails (local public disk); private
     * s3 media falls back to a short-lived presigned URL — null while
     * storage is disabled or not configured. Also null once the stored
     * object was purged by cleanup — callers render a placeholder instead
     * of a broken URL.
     */
    public function forPreview(MediaFile $media): ?string
    {
        if ($media->purged_at !== null) {
            return null;
        }

        try {
            if ($media->disk === 's3') {
                if (! $this->storage->configured()) {
                    return null;
                }

                return $this->storage->diskFor('s3')
                    ->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
            }

            return $this->storage->diskFor($media->disk)->url($media->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
