<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\MediaFile;
use App\Models\Story;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /** Server-detected MIME types accepted by v1 (spec H.4). */
    private const ACCEPTED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
    ];

    public function __construct(
        private readonly MediaUrlResolver $urls,
        private readonly MediaStorage $storage,
    ) {}

    /**
     * Upload media. Validation trusts content detection on the server, never
     * the client-provided extension (spec H.4).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // 51200 KiB = 52,428,800 bytes = 50 MiB.
            'file' => ['required', 'file', 'max:51200'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        // Detect the MIME type from the actual bytes: the client-provided
        // extension and content type are never trusted (spec H.4).
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($file->getContent());

        if (! array_key_exists($mime, self::ACCEPTED_MIME_EXTENSIONS)) {
            return $this->uploadFailure('file', 'Unsupported media type. Accepted: JPEG, PNG, WebP images and MP4 video that matches the WhatsApp format.');
        }

        // WAHA downloads media itself, so uploads are S3-only: without a
        // reachable bucket there is no URL WAHA could fetch. The gate reads
        // the storage row, so disabling storage in Settings blocks uploads
        // immediately.
        if (! $this->storage->configured()) {
            return $this->uploadFailure('file', 'S3 storage is disabled or not configured. Enable and configure it in Settings → Storage & auto-cleanup.');
        }

        $diskName = 's3';
        $path = sprintf(
            'stories/%s/%s/%s.%s',
            now()->format('Y'),
            now()->format('m'),
            (string) Str::uuid(),
            self::ACCEPTED_MIME_EXTENSIONS[$mime],
        );

        // Disks are built with throw=false, so a failed write is only
        // visible through the return value — surface it instead of silently
        // creating a media row whose bytes never landed. Resolution itself
        // can also throw (row not configured); that must be a friendly 422 too.
        try {
            $stored = $this->storage->diskFor($diskName)->put($path, $file->getContent());
        } catch (\Throwable) {
            $stored = false;
        }

        if ($stored === false) {
            return $this->uploadFailure('file', 'Storing the upload failed on the configured disk. Check the storage settings and try again.');
        }

        $media = MediaFile::create([
            'disk' => $diskName,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'video_codec' => $mime === 'video/mp4' ? $this->detectVideoCodec($file) : null,
            'created_by' => $request->user()->id,
        ]);

        $payload = [
            'id' => $media->id,
            'original_name' => $media->original_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size_bytes,
            'video_codec' => $media->video_codec,
            'url' => $this->urls->forPreview($media),
        ];

        return response()->json($payload);
    }

    /**
     * Delete an uploaded file that is not referenced by any story/broadcast.
     */
    public function destroy(MediaFile $media): JsonResponse
    {
        $referenced = Story::where('media_file_id', $media->id)->exists()
            || Broadcast::where('media_file_id', $media->id)->exists();

        if ($referenced) {
            return response()->json(['message' => 'Media is referenced by a story or broadcast and cannot be deleted.'], 409);
        }

        // Disable semantics are per-row (spec D3): only s3 rows are blocked
        // while storage is disabled — legacy public rows stay deletable.
        if ($media->disk === 's3' && ! $this->storage->configured()) {
            return response()->json(['message' => 'Storage is disabled; the file cannot be removed right now.'], 409);
        }

        $this->storage->diskFor($media->disk)->delete($media->path);
        $media->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Best-effort codec detection via ffprobe when available (spec D2):
     * absence simply leaves the codec unknown; the UI explains the limits.
     */
    private function detectVideoCodec(UploadedFile $file): ?string
    {
        $ffprobe = trim((string) exec('command -v ffprobe 2>/dev/null'));

        if ($ffprobe === '' || ! is_file($ffprobe)) {
            return null;
        }

        $path = escapeshellarg($file->getRealPath());
        $codec = exec("{$ffprobe} -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 {$path} 2>/dev/null", $_, $code);

        return $code === 0 && is_string($codec) && $codec !== '' ? trim($codec) : null;
    }

    private function uploadFailure(string $key, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$key => [$message]]], 422);
    }
}
