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

    public function forSend(MediaFile $media): string
    {
        $disk = Storage::disk($media->disk);

        if ($this->diskSupportsTemporaryUrls($media->disk)) {
            return $disk->temporaryUrl($media->path, now()->addMinutes(self::SIGNED_URL_TTL_MINUTES));
        }

        return $disk->url($media->path);
    }

    /**
     * Long-lived preview URL for UI thumbnails (local public disk or bucket
     * custom domain); falls back to a temporary URL for private disks.
     */
    public function forPreview(MediaFile $media): string
    {
        $disk = Storage::disk($media->disk);

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
