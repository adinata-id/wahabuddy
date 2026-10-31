<?php

namespace App\Console\Commands;

use App\Services\Waha\ConnectionRepository;
use Illuminate\Console\Command;

/**
 * Explicit one-shot import of WAHA_* env values into the database connection
 * row (spec E): env values never silently override stored settings — this
 * command must be run by hand.
 */
class ImportConnectionFromEnv extends Command
{
    protected $signature = 'wahabuddy:import-env';

    protected $description = 'Import WAHA_BASE_URL/WAHA_API_KEY/WAHA_SESSION from .env into the stored connection';

    public function handle(ConnectionRepository $connections): int
    {
        $baseUrl = trim((string) config('wahabuddy.import.base_url', ''));
        $apiKey = trim((string) config('wahabuddy.import.api_key', ''));
        $session = trim((string) config('wahabuddy.import.session', ''));

        if ($baseUrl === '' && $apiKey === '' && $session === '') {
            $this->info('No WAHA_BASE_URL/WAHA_API_KEY/WAHA_SESSION values found in the environment; nothing to import.');

            return self::SUCCESS;
        }

        $connections->save(array_filter([
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'api_key' => $apiKey !== '' ? $apiKey : null,
            'session' => $session !== '' ? $session : null,
        ], fn ($value) => $value !== null));

        $this->info('Imported WAHA connection values from the environment into the database.');

        return self::SUCCESS;
    }
}
