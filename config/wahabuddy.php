<?php

return [

    /*
    |----------------------------------------------------------------------
    | Display timezone fallback
    |----------------------------------------------------------------------
    | Used when the app_settings row is first created. All persistence stays
    | UTC; this only affects presentation and new schedule input.
    */
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Jakarta'),

    /*
    |----------------------------------------------------------------------
    | Private network targets
    |----------------------------------------------------------------------
    | Self-hosted WAHA frequently runs on a LAN or in a container network, so
    | private targets are allowed by default — an explicit opt-out exists for
    | stricter deployments. Cloud metadata and link-local targets are always
    | rejected regardless of this flag (see App\Services\Waha\WahaUrl).
    */
    'allow_private_networks' => env('WAHA_ALLOW_PRIVATE_NETWORKS', true),

    /*
    |----------------------------------------------------------------------
    | Optional initial import values (spec E)
    |----------------------------------------------------------------------
    | Only consumed by `php artisan wahabuddy:import-env`, never by runtime
    | services: stored database settings always win for actual sends.
    */
    'import' => [
        'base_url' => env('WAHA_BASE_URL'),
        'api_key' => env('WAHA_API_KEY'),
        'session' => env('WAHA_SESSION'),
    ],

    /*
    |----------------------------------------------------------------------
    | Media storage
    |----------------------------------------------------------------------
    | Default disk for story/broadcast media: "public" (local, world-readable
    | URLs) or "s3" (S3-compatible / R2 with presigned URLs per send).
    */
    'media_disk' => env('MEDIA_DISK', 'public'),

    /*
    |----------------------------------------------------------------------
    | HTTP behavior toward WAHA
    |----------------------------------------------------------------------
    | Send requests must never auto-retry (non-idempotent POSTs); read-only
    | GETs may retry once. Timeouts follow the spec: 150s send, 10s connect.
    */
    'http' => [
        'send_timeout' => 150,
        'test_timeout' => 10,
        'connect_timeout' => 10,
        'get_retry_after' => true,
    ],

    /*
    |----------------------------------------------------------------------
    | Story scheduling (spec section G)
    |----------------------------------------------------------------------
    */
    'stories' => [
        'max_send_attempts' => 3,
        'expire_after_days' => 7,
        'stale_sending_minutes' => 15,
        'safe_backoff_seconds' => [60, 300],
        'rate_limit_fallback_seconds' => 60,
    ],
];
