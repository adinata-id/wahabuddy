<?php

namespace App\Services\Waha;

use App\Services\Waha\DTO\ConnectionCheck;
use App\Services\Waha\DTO\ConnectionSnapshot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP adapter for the self-hosted WAHA instance.
 *
 * Task 2 scope: read-only connection checking. Task 3 adds the send/delete
 * endpoints with their strict ambiguity semantics (no retry on POST).
 *
 * All requests go out with the configured X-Api-Key header and optional Basic
 * credentials; error messages produced here are static strings so no secret or
 * raw remote payload can leak into responses or logs.
 */
final class WahaClient
{
    /**
     * Read-only connection test: GET /api/sessions/{session}.
     *
     * Only `status === "WORKING"` allows sends; anything else (including
     * authentication or network failures) is reported without throwing.
     */
    public function checkConnection(ConnectionSnapshot $connection): ConnectionCheck
    {
        $testedAt = new \DateTimeImmutable;

        if (! $connection->isConfigured()) {
            return $this->check($testedAt, false, errorCode: 'not_configured', error: 'The WAHA base URL is not configured yet.');
        }

        try {
            $response = $this
                ->request($connection)
                ->get($connection->baseUrl.'/api/sessions/'.WahaUrl::sessionSegment($connection->session));
        } catch (ConnectionException) {
            return $this->check($testedAt, false, errorCode: 'network', error: 'Could not reach the WAHA server. Check the base URL, network, and whether WAHA is running.');
        }

        return match (true) {
            $response->status() === 200 => $this->checkFromSessionPayload($response, $testedAt, $connection->session),
            $response->status() === 401 => $this->check($testedAt, false, errorCode: 'auth_rejected', error: 'WAHA rejected the request as unauthenticated. Verify the API key.'),
            $response->status() === 403 => $this->check($testedAt, false, errorCode: 'forbidden', error: 'Access was denied (403). This is not necessarily a wrong session — check API key permissions or any reverse-proxy in front of WAHA.'),
            $response->status() === 404 => $this->check($testedAt, false, errorCode: 'not_found', error: 'The session or endpoint was not found (404). Check the base URL path and the session name.'),
            $response->serverError() => $this->check($testedAt, false, errorCode: 'server_error', error: 'WAHA answered with a server error (5xx). Check the WAHA logs.'),
            default => $this->check($testedAt, false, errorCode: 'unexpected_status', error: "Unexpected response status {$response->status()}."),
        };
    }

    /**
     * Base HTTP request with authentication headers and read timeouts.
     */
    private function request(ConnectionSnapshot $connection): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout(config('wahabuddy.http.test_timeout', 10))
            ->connectTimeout(config('wahabuddy.http.connect_timeout', 5));

        if ($connection->apiKey !== null && $connection->apiKey !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $connection->apiKey]);
        }

        if ($connection->basicUsername !== null && $connection->basicUsername !== '') {
            $request = $request->withBasicAuth($connection->basicUsername, (string) $connection->basicPassword);
        }

        return $request;
    }

    private function checkFromSessionPayload(Response $response, \DateTimeImmutable $testedAt, string $configuredSession): ConnectionCheck
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return $this->check($testedAt, false, errorCode: 'invalid_payload', error: 'WAHA returned an unexpected payload for the session.');
        }

        $status = isset($payload['status']) && is_string($payload['status']) ? $payload['status'] : null;
        $name = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : null;

        $engine = null;
        if (isset($payload['engine']) && is_string($payload['engine'])) {
            $engine = $payload['engine'];
        } elseif (isset($payload['engine']['name']) && is_string($payload['engine']['name'])) {
            $engine = $payload['engine']['name'];
        }

        $version = isset($payload['wahaVersion']) && is_string($payload['wahaVersion'])
            ? $payload['wahaVersion']
            : (isset($payload['version']) && is_string($payload['version']) ? $payload['version'] : null);

        $me = $this->sanitizeMe($payload['me'] ?? null);

        if ($status === null) {
            return $this->check(
                $testedAt,
                false,
                sessionStatus: null,
                sessionName: $name,
                me: $me,
                engine: $engine,
                wahaVersion: $version,
                errorCode: 'invalid_payload',
                error: 'The session response did not include a status field.',
            );
        }

        $working = strtoupper($status) === 'WORKING';

        return $this->check(
            $testedAt,
            $working,
            sessionStatus: strtoupper($status),
            sessionName: $name ?? $configuredSession,
            me: $me,
            engine: $engine,
            wahaVersion: $version,
            errorCode: $working ? null : 'session_not_working',
            error: $working ? null : "Session status is {$status}; only WORKING sessions can send.",
        );
    }

    /**
     * Keep only non-sensitive identity fields from the `me` object.
     *
     * @return array<string, string>|null
     */
    private function sanitizeMe(mixed $me): ?array
    {
        if (! is_array($me)) {
            return null;
        }

        $chatId = null;
        if (isset($me['chatId']) && is_string($me['chatId'])) {
            $chatId = $me['chatId'];
        } elseif (isset($me['id'])) {
            if (is_string($me['id'])) {
                $chatId = $me['id'];
            } elseif (is_array($me['id']) && isset($me['id']['_serialized']) && is_string($me['id']['_serialized'])) {
                $chatId = $me['id']['_serialized'];
            }
        }

        $name = null;
        foreach (['pushname', 'name'] as $field) {
            if (isset($me[$field]) && is_string($me[$field]) && $me[$field] !== '') {
                $name = $me[$field];

                break;
            }
        }

        if ($chatId === null && $name === null) {
            return null;
        }

        return array_filter(['chatId' => $chatId, 'name' => $name], fn ($value) => $value !== null);
    }

    private function check(
        \DateTimeImmutable $testedAt,
        bool $working,
        ?string $sessionStatus = null,
        ?string $sessionName = null,
        ?array $me = null,
        ?string $engine = null,
        ?string $wahaVersion = null,
        ?string $errorCode = null,
        ?string $error = null,
    ): ConnectionCheck {
        return new ConnectionCheck(
            working: $working,
            sessionStatus: $sessionStatus,
            sessionName: $sessionName,
            me: $me,
            engine: $engine,
            wahaVersion: $wahaVersion,
            errorCode: $errorCode,
            error: $error,
            testedAt: $testedAt,
        );
    }
}
