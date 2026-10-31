<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\WahaConnection;
use App\Services\Waha\CapabilityResolver;
use App\Services\Waha\ConnectionRepository;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * The settings page. Secrets never leave the backend: only has_* flags
     * and a masked hint are exposed (spec E/K).
     */
    public function index(ConnectionRepository $connections, CapabilityResolver $capabilities)
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
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
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
