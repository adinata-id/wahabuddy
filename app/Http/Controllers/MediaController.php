<?php

namespace App\Http\Controllers;

use App\Models\Broadcast;
use App\Models\MediaFile;
use App\Models\Story;
use App\Services\Media\MediaUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(private readonly MediaUrlResolver $urls) {}

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

        $diskName = (string) config('wahabuddy.media_disk', 'public');
        $path = sprintf(
            'stories/%s/%s/%s.%s',
            now()->format('Y'),
            now()->format('m'),
            (string) Str::uuid(),
            self::ACCEPTED_MIME_EXTENSIONS[$mime],
        );

        Storage::disk($diskName)->put($path, $file->getContent());

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

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Storage probe: write a tiny object, fetch it from the backend with a
     * short timeout, then always clean up (spec H.6). Backend reachability is
     * NOT proof that the WAHA network can reach the URL.
     */
    public function testStorage(Request $request): JsonResponse
    {
        $diskName = (string) config('wahabuddy.media_disk', 'public');
        $disk = Storage::disk($diskName);
        $path = 'stories/probe-'.Str::uuid().'.txt';
        $url = null;

        try {
            $disk->put($path, 'wahabuddy storage probe');

            $probe = new MediaFile([
                'disk' => $diskName,
                'path' => $path,
                'original_name' => basename($path),
                'mime_type' => 'text/plain',
                'size_bytes' => 22,
                'created_by' => $request->user()->id,
            ]);
            $url = $this->urls->forPreview($probe);

            $response = Http::timeout(5)->get($url);
            $reachable = $response->successful() && trim($response->body()) === 'wahabuddy storage probe';
            $status = $response->status();
        } catch (\Throwable) {
            $reachable = false;
            $status = null;
        } finally {
            $disk->delete($path);
        }

        return response()->json([
            'reachable' => $reachable,
            'status' => $status,
            'url' => $reachable ? $url : null,
            'note' => 'A successful backend fetch does not prove the URL is reachable from the WAHA container network; run an end-to-end story test to confirm.',
        ]);
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
