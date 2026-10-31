<?php

namespace App\Services\Waha;

use App\Enums\BroadcastType;
use App\Enums\StoryType;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Story;
use App\Services\Waha\DTO\ConnectionCheck;
use App\Services\Waha\DTO\ConnectionSnapshot;
use App\Services\Waha\DTO\SendResult;
use App\Services\Waha\Exceptions\WahaRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP adapter for the self-hosted WAHA instance.
 *
 * All requests go out with the configured X-Api-Key header and optional Basic
 * credentials; error messages produced here are static or redacted strings so
 * no secret or raw remote payload can leak into responses or logs.
 *
 * Send semantics (spec D2/G2): POST sends never retry — timeouts, connection
 * loss and 5xx after the request may have been processed are reported as
 * `unknown`, and a 2xx response without a usable message id is `unknown` too.
 * Read-only GETs may retry once with a short backoff.
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
     * Sessions available on the target (GET /api/sessions).
     *
     * @return array<int, array{name: ?string, status: ?string}>
     */
    public function listSessions(ConnectionSnapshot $connection): array
    {
        $payload = $this->getJson($connection, '/api/sessions');

        return array_values(array_map(function ($entry) {
            if (! is_array($entry)) {
                return ['name' => null, 'status' => null];
            }

            return [
                'name' => isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : null,
                'status' => isset($entry['status']) && is_string($entry['status']) ? $entry['status'] : null,
            ];
        }, is_array($payload) ? $payload : []));
    }

    /**
     * Contacts for the selected session (GET /api/contacts/all?session=…).
     *
     * @return array<int, mixed>
     */
    public function listContacts(ConnectionSnapshot $connection): array
    {
        $payload = $this->getJson($connection, '/api/contacts/all', ['session' => $connection->session]);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Contacts page with limit/offset pagination (spec D2/I).
     *
     * @return array<int, mixed>
     */
    public function listContactsOffset(ConnectionSnapshot $connection, int $limit, int $offset): array
    {
        $payload = $this->getJson($connection, '/api/contacts/all', [
            'session' => $connection->session,
            'limit' => (string) $limit,
            'offset' => (string) $offset,
        ]);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Chats/groups for the selected session (GET /api/{session}/chats).
     *
     * @return array<int, mixed>
     */
    public function listChats(ConnectionSnapshot $connection): array
    {
        $payload = $this->getJson($connection, '/api/'.WahaUrl::sessionSegment($connection->session).'/chats');

        return is_array($payload) ? $payload : [];
    }

    /**
     * Send a story (status) through the selected session.
     */
    public function sendStory(ConnectionSnapshot $connection, Story $story, ?string $mediaUrl): SendResult
    {
        $sessionPath = '/api/'.WahaUrl::sessionSegment($connection->session).'/status/';

        $payload = match ($story->type) {
            StoryType::Text => [
                'text' => (string) $story->text,
                'backgroundColor' => $story->background_color,
                'font' => $story->font,
            ],
            StoryType::Image => ['file' => $this->fileObject($story, $mediaUrl)],
            StoryType::Video => [
                'file' => $this->fileObject($story, $mediaUrl, includeFilename: true),
                'convert' => false,
            ],
        };

        if ($story->type !== StoryType::Text && $story->text !== null && $story->text !== '') {
            $payload['caption'] = $story->text;
        }

        $response = $this->post($connection, $sessionPath.$story->type->value, $payload);

        return $this->sendResultFromResponse($response);
    }

    /**
     * Send one broadcast message to one recipient.
     */
    public function sendBroadcast(ConnectionSnapshot $connection, BroadcastRecipient $recipient, Broadcast $broadcast, ?string $mediaUrl): SendResult
    {
        [$path, $payload] = match ($broadcast->type) {
            BroadcastType::Text => [
                '/api/sendText',
                [
                    'session' => $connection->session,
                    'chatId' => $recipient->chat_id,
                    'text' => $recipient->rendered_text,
                ],
            ],
            BroadcastType::Image => [
                '/api/sendImage',
                [
                    'session' => $connection->session,
                    'chatId' => $recipient->chat_id,
                    'file' => [
                        'mimetype' => (string) $broadcast->mediaFile?->mime_type,
                        'url' => (string) $mediaUrl,
                    ],
                    'caption' => $recipient->rendered_text,
                ],
            ],
        };

        if ($broadcast->type === BroadcastType::Image && ($mediaUrl === null || $broadcast->mediaFile === null)) {
            throw new \InvalidArgumentException('Image broadcasts require media and a fresh media URL.');
        }

        $response = $this->post($connection, $path, $payload);

        return $this->sendResultFromResponse($response);
    }

    /**
     * Delete a previously sent status using its key id. Capability and original
     * target must allow it; never retried automatically.
     */
    public function deleteStatus(ConnectionSnapshot $connection, string $keyId): void
    {
        $capability = $connection->capabilities['delete_status']['state'] ?? null;

        if ($capability === 'unsupported') {
            throw new WahaRequestException(
                errorCode: 'capability_unsupported',
                httpStatus: null,
                safeMessage: 'Status deletion is marked unsupported for this WAHA target.',
                outcome: 'rejected',
                retryable: false,
            );
        }

        $response = $this->post(
            $connection,
            '/api/'.WahaUrl::sessionSegment($connection->session).'/status/delete',
            ['id' => $keyId],
        );

        if ($response->successful()) {
            return;
        }

        throw $this->exceptionFromResponse($response, 'delete');
    }

    /**
     * Send-target POST: send timeouts, no retry of any kind (spec G2).
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(ConnectionSnapshot $connection, string $path, array $payload): Response
    {
        try {
            return $this->sendRequest($connection)->post($connection->baseUrl.$path, $payload);
        } catch (ConnectionException $exception) {
            throw new WahaRequestException(
                errorCode: 'network_timeout',
                httpStatus: null,
                safeMessage: 'The request to WAHA timed out or the connection was lost. Delivery cannot be confirmed.',
                outcome: 'unknown',
                retryable: false,
            );
        }
    }

    /**
     * Read-only GET with a single short retry allowed (spec G2).
     *
     * @param  array<string, string>  $query
     */
    private function getJson(ConnectionSnapshot $connection, string $path, array $query = []): mixed
    {
        try {
            $request = $this->sendRequest($connection)->retry(1, 200, throw: false);

            return $request->get($connection->baseUrl.$path, $query)->json();
        } catch (ConnectionException) {
            throw new WahaRequestException(
                errorCode: 'network_timeout',
                httpStatus: null,
                safeMessage: 'The request to WAHA timed out or the connection was lost.',
                outcome: 'unknown',
                retryable: false,
            );
        }
    }

    /**
     * File object for image/video payloads, URL always derived from trusted
     * storage per send — never persisted or logged.
     *
     * @return array<string, string>
     */
    private function fileObject(Story $story, ?string $mediaUrl, bool $includeFilename = false): array
    {
        $media = $story->mediaFile;

        if ($media === null || $mediaUrl === null || $mediaUrl === '') {
            throw new \InvalidArgumentException('Image/video stories require uploaded media and a fresh media URL.');
        }

        $file = [
            'mimetype' => $media->mime_type,
            'url' => $mediaUrl,
        ];

        if ($includeFilename) {
            $file['filename'] = $media->original_name;
        }

        return $file;
    }

    /**
     * Turn a send response into a SendResult, enforcing the id invariant.
     */
    private function sendResultFromResponse(Response $response): SendResult
    {
        if (! $response->successful()) {
            throw $this->exceptionFromResponse($response, 'send');
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $messageId = null;
        if (isset($payload['id'])) {
            if (is_string($payload['id']) && $payload['id'] !== '') {
                $messageId = $payload['id'];
            } elseif (is_array($payload['id'])) {
                $serialized = $payload['id']['_serialized'] ?? null;
                $inner = $payload['id']['id'] ?? null;
                $messageId = is_string($serialized) && $serialized !== ''
                    ? $serialized
                    : (is_string($inner) && $inner !== '' ? $inner : null);
            }
        }

        $keyId = null;
        if (isset($payload['key']['id']) && is_string($payload['key']['id']) && $payload['key']['id'] !== '') {
            $keyId = $payload['key']['id'];
        }

        if ($messageId === null && $keyId === null) {
            throw new WahaRequestException(
                errorCode: 'send_result_without_id',
                httpStatus: $response->status(),
                safeMessage: 'WAHA accepted the request but returned no message id. Delivery cannot be confirmed.',
                outcome: 'unknown',
                retryable: false,
            );
        }

        return new SendResult(messageId: $messageId, keyId: $keyId);
    }

    /**
     * Classify an error response into sanitized, retry-safe semantics.
     */
    private function exceptionFromResponse(Response $response, string $context): WahaRequestException
    {
        $status = $response->status();
        $detail = $this->sanitizedDetail($response);

        return match (true) {
            $status === 401 => new WahaRequestException('auth_rejected', $status, 'WAHA rejected the request as unauthenticated. Verify the API key.'.$detail, 'rejected'),
            $status === 403 => new WahaRequestException('forbidden', $status, 'Access was denied (403). Check API key permissions or any reverse-proxy in front of WAHA.'.$detail, 'rejected'),
            $status === 404 => new WahaRequestException('not_found', $status, 'The endpoint, session, or target was not found (404).'.$detail, 'rejected'),
            $status === 429 => new WahaRequestException(
                'rate_limited',
                $status,
                'WAHA is rate limiting requests (429).'.$detail,
                'rejected',
                retryable: true,
                retryAfterSeconds: $this->retryAfterSeconds($response),
            ),
            $status === 400 || $status === 422 => new WahaRequestException('request_rejected', $status, 'WAHA rejected the payload as invalid ('.$status.').'.$detail, 'rejected'),
            $response->serverError() => new WahaRequestException(
                'server_error',
                $status,
                'WAHA answered with a server error after the request may have been processed. Delivery cannot be confirmed.'.$detail,
                'unknown',
            ),
            default => new WahaRequestException(
                'unexpected_status',
                $status,
                'Unexpected response status '.$status.'.'.$detail,
                // Statuses outside the known families after a send stay unknown.
                'unknown',
            ),
        };
    }

    /**
     * Short, redacted excerpt of the remote message — never a raw dump.
     */
    private function sanitizedDetail(Response $response): string
    {
        $message = $response->json('message');

        if (! is_string($message) || $message === '') {
            return '';
        }

        $clean = preg_replace('/[^\P{C}\n]+/u', '', $message) ?? '';
        $clean = trim(str_replace("\n", ' ', $clean));

        if ($clean === '') {
            return '';
        }

        return ' Detail: '.mb_substr($clean, 0, 300);
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $value);

        return $date === false ? null : max(0, $date->getTimestamp() - time());
    }

    /**
     * Send-target HTTP client: 150s timeout / 10s connect per spec G2.
     */
    private function sendRequest(ConnectionSnapshot $connection): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout((int) config('wahabuddy.http.send_timeout', 150))
            ->connectTimeout((int) config('wahabuddy.http.connect_timeout', 10));

        if ($connection->apiKey !== null && $connection->apiKey !== '') {
            $request = $request->withHeaders(['X-Api-Key' => $connection->apiKey]);
        }

        if ($connection->basicUsername !== null && $connection->basicUsername !== '') {
            $request = $request->withBasicAuth($connection->basicUsername, (string) $connection->basicPassword);
        }

        return $request;
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

    /**
     * @param  array<string, string>|null  $me
     */
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
