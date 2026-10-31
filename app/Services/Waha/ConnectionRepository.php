<?php

namespace App\Services\Waha;

use App\Models\WahaConnection;
use App\Services\Waha\DTO\ConnectionSnapshot;

/**
 * Owns persistence and resolution of the singleton WAHA connection row.
 *
 * Blank secret fields on update mean "keep the stored value"; every effective
 * change to the send target (URL, session, or credentials) bumps `revision` so
 * queued work can be held until an admin confirms the new target (spec E).
 */
final class ConnectionRepository
{
    public function current(): WahaConnection
    {
        return WahaConnection::singleton();
    }

    public function snapshot(?WahaConnection $connection = null): ConnectionSnapshot
    {
        $connection ??= $this->current();

        return new ConnectionSnapshot(
            baseUrl: $connection->base_url,
            apiKey: $connection->api_key,
            basicUsername: $connection->basic_username,
            basicPassword: $connection->basic_password,
            session: $connection->session ?? 'default',
            revision: (int) ($connection->revision ?? 1),
            capabilities: $connection->capabilities ?? [],
        );
    }

    /**
     * Build the snapshot a settings form refers to: submitted values win,
     * blank/omitted secret fields fall back to the stored ones.
     *
     * @param  array<string, mixed>  $data  validated request data
     */
    public function resolveForm(array $data): ConnectionSnapshot
    {
        $stored = $this->current();

        return new ConnectionSnapshot(
            baseUrl: WahaUrl::normalize($data['base_url'] ?? null, config('wahabuddy.allow_private_networks', true))
                ?? $stored->base_url,
            apiKey: filled($data['api_key'] ?? null) ? (string) $data['api_key'] : $stored->api_key,
            basicUsername: array_key_exists('basic_username', $data)
                ? (filled($data['basic_username']) ? (string) $data['basic_username'] : null)
                : $stored->basic_username,
            basicPassword: filled($data['basic_password'] ?? null) ? (string) $data['basic_password'] : $stored->basic_password,
            session: filled($data['session'] ?? null) ? (string) $data['session'] : $stored->session,
            revision: $stored->revision,
            capabilities: $stored->capabilities ?? [],
        );
    }

    /**
     * Persist a validated connection update with keep-blank secret semantics
     * and revision bumping on effective target changes. Initial configuration
     * of a previously empty row is setup, not a target change: no queued work
     * against an old target can exist yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): WahaConnection
    {
        $connection = $this->current();
        $resolved = $this->resolveForm($data);

        $wasConfigured = filled($connection->base_url);
        $targetChanged = $wasConfigured
            && $resolved->configHash() !== $this->snapshot($connection)->configHash();

        $connection->forceFill([
            'base_url' => $resolved->baseUrl,
            'api_key' => $resolved->apiKey,
            'basic_username' => $resolved->basicUsername,
            'basic_password' => $resolved->basicPassword,
            'session' => $resolved->session,
            'revision' => $targetChanged ? $connection->revision + 1 : $connection->revision,
        ]);
        $connection->save();

        return $connection;
    }

    public function clearApiKey(): WahaConnection
    {
        $connection = $this->current();
        $connection->forceFill([
            'api_key' => null,
            'revision' => $connection->revision + 1,
        ]);
        $connection->save();

        return $connection;
    }

    public function clearBasicAuth(): WahaConnection
    {
        $connection = $this->current();
        $connection->forceFill([
            'basic_username' => null,
            'basic_password' => null,
            'revision' => $connection->revision + 1,
        ]);
        $connection->save();

        return $connection;
    }

    /**
     * Store a sanitized test result only when it was produced by testing the
     * currently saved configuration (spec E: results are bound to a config hash).
     */
    public function recordTestResult(WahaConnection $connection, ConnectionSnapshot $tested, string $status, ?string $error): void
    {
        if ($tested->configHash() !== $this->snapshot($connection)->configHash()) {
            return;
        }

        $connection->forceFill([
            'last_test_at' => now(),
            'last_test_status' => $status,
            'last_test_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
        $connection->save();
    }
}
