<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\MediaCleanupRun;
use App\Models\MediaFile;
use App\Models\WahaConnection;
use App\Services\Media\SentMediaCleanup;
use App\Services\Waha\CapabilityResolver;
use App\Services\Waha\ConnectionRepository;
use Inertia\Inertia;
use Inertia\Response;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class SettingsController extends Controller
{
    /**
     * The settings page. Secrets never leave the backend: only has_* flags
     * and a masked hint are exposed (spec E/K).
     */
    public function index(ConnectionRepository $connections, CapabilityResolver $capabilities, SentMediaCleanup $cleanup): Response
    {
        $connection = $connections->current();
        $preferences = AppSetting::singleton();

        return Inertia::render('settings/index', [
            'connection' => $this->connectionProps($connection, $capabilities->overview($connection)),
            'preferences' => [
                'display_timezone' => $preferences->display_timezone,
                'broadcast_delay_min' => $preferences->broadcast_delay_min,
                'broadcast_delay_max' => $preferences->broadcast_delay_max,
                'broadcast_batch_size' => $preferences->broadcast_batch_size,
                'broadcast_batch_pause' => $preferences->broadcast_batch_pause,
            ],
            'storage' => $this->storageProps($preferences, $cleanup),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Everything the storage card shows: disk selection, cleanup schedule,
     * a live eligible-now estimate, per-disk usage, and run history.
     *
     * @return array<string, mixed>
     */
    private function storageProps(AppSetting $preferences, SentMediaCleanup $cleanup): array
    {
        $eligible = $cleanup->eligibleQuery($preferences);

        $diskUsage = MediaFile::query()
            ->whereNull('purged_at')
            ->selectRaw('disk, count(*) as files, coalesce(sum(size_bytes), 0) as bytes')
            ->groupBy('disk')
            ->orderBy('disk')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['disk' => $row->disk, 'files' => (int) $row->files, 'bytes' => (int) $row->bytes])
            ->values()
            ->all();

        $recentRuns = MediaCleanupRun::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (MediaCleanupRun $run) => [
                'id' => $run->id,
                'trigger' => $run->trigger,
                'status' => $run->status,
                'files_purged' => $run->files_purged,
                'bytes_freed' => $run->bytes_freed,
                'files_failed' => $run->files_failed,
                'capped' => $run->capped,
                'message' => $run->message,
                'finished_at' => $run->finished_at?->toAtomString(),
            ])
            ->values()
            ->all();

        return [
            'media_disk' => $preferences->media_disk,
            'media_public_base_url' => $preferences->media_public_base_url,
            'cleanup' => [
                'enabled' => (bool) $preferences->media_cleanup_enabled,
                'frequency' => $preferences->media_cleanup_frequency,
                'day' => $preferences->media_cleanup_day,
                'interval_days' => $preferences->media_cleanup_interval_days,
                'retention_days' => (int) $preferences->media_cleanup_retention_days,
                'include_terminal' => (bool) $preferences->media_cleanup_include_terminal,
                'last_run_at' => $preferences->media_cleanup_last_run_at?->toAtomString(),
                'next_run_at' => $preferences->media_cleanup_next_run_at?->toAtomString(),
            ],
            'estimate' => [
                'files' => (clone $eligible)->count(),
                'bytes' => (int) (clone $eligible)->sum('size_bytes'),
            ],
            'disk_usage' => $diskUsage,
            'capabilities' => [
                's3_adapter_installed' => class_exists(AwsS3V3Adapter::class),
                's3_credentials_present' => filled(config('filesystems.disks.s3.key'))
                    && filled(config('filesystems.disks.s3.secret'))
                    && filled(config('filesystems.disks.s3.bucket')),
            ],
            'recent_runs' => $recentRuns,
        ];
    }

    /**
     * @param  array<string, array{state: string, source: string}>  $capabilities
     * @return array<string, mixed>
     */
    public static function connectionProps(WahaConnection $connection, array $capabilities): array
    {
        return [
            'base_url' => $connection->base_url,
            'session' => $connection->session,
            'basic_username' => $connection->basic_username,
            'has_api_key' => filled($connection->api_key),
            'api_key_hint' => self::maskHint($connection->api_key),
            'has_basic_password' => filled($connection->basic_password),
            'revision' => (int) $connection->revision,
            'capabilities' => $capabilities,
            'last_test' => $connection->last_test_at === null ? null : [
                'at' => $connection->last_test_at->toAtomString(),
                'status' => $connection->last_test_status,
                'error' => $connection->last_test_error,
            ],
        ];
    }

    /**
     * Safe hint: at most the last four characters, only when a key exists.
     */
    private static function maskHint(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return '…'.mb_substr($secret, -4);
    }
}
