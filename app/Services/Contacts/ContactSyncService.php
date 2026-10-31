<?php

namespace App\Services\Contacts;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Services\Waha\DTO\ConnectionSnapshot;
use App\Services\Waha\Exceptions\WahaRequestException;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\DB;

/**
 * Paginated contact/group sync from WAHA (spec I).
 *
 * Read-only GETs; never sends. Syncing does NOT opt anyone in — consent is a
 * separate explicit step. Partial failures are reported instead of silently
 * dropped.
 */
final class ContactSyncService
{
    private const MAX_PAGES = 100;

    public function __construct(private readonly WahaClient $client) {}

    /**
     * @return array{
     *     synced: int,
     *     skipped_invalid: int,
     *     pages: int,
     *     partial_failure: ?string,
     *     contacts: int,
     *     groups: int
     * }
     */
    public function sync(ConnectionSnapshot $connection, int $perPage = 100): array
    {
        $synced = 0;
        $skipped = 0;
        $pages = 0;
        $partialFailure = null;
        $offset = 0;

        $seen = [];

        // Contacts (paginated until exhaustion or the safety cap).
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $batch = $this->fetchContactsPage($connection, $perPage, $offset);
            } catch (WahaRequestException $exception) {
                $partialFailure = 'Contact listing failed after '.$pages.' page(s): '.$exception->safeMessage;

                break;
            }

            $pages++;

            foreach ($batch as $entry) {
                $normalized = $this->normalizeEntry($entry);

                if ($normalized === null) {
                    $skipped++;

                    continue;
                }

                $seen[$normalized['chat_id']] = $normalized;
            }

            $offset += count($batch);

            if (count($batch) < $perPage) {
                break;
            }
        }

        // Chats/groups (session path per D2).
        try {
            foreach ($this->client->listChats($connection) as $chat) {
                $normalized = $this->normalizeEntry($chat);

                if ($normalized === null) {
                    continue;
                }

                // Only group chats from the chats listing: contacts already
                // come from the contacts endpoint with better names.
                if ($normalized['type'] !== ContactType::Group) {
                    continue;
                }

                $seen[$normalized['chat_id']] = $normalized;
            }
        } catch (WahaRequestException $exception) {
            $partialFailure = 'Chat listing failed: '.$exception->safeMessage;
        }

        $contacts = 0;
        $groups = 0;

        DB::transaction(function () use ($seen, $connection, &$synced, &$contacts, &$groups): void {
            foreach ($seen as $chatId => $normalized) {
                Contact::updateOrCreate(
                    [
                        'connection_revision' => $connection->revision,
                        'chat_id' => $chatId,
                    ],
                    array_filter([
                        'name' => $normalized['name'],
                        'type' => $normalized['type']->value,
                    ], fn ($value) => $value !== null) + ['type' => $normalized['type']->value],
                );

                $synced++;

                if ($normalized['type'] === ContactType::Group) {
                    $groups++;
                } else {
                    $contacts++;
                }
            }
        });

        return [
            'synced' => $synced,
            'skipped_invalid' => $skipped,
            'pages' => $pages,
            'partial_failure' => $partialFailure,
            'contacts' => $contacts,
            'groups' => $groups,
        ];
    }

    /**
     * @return array<int, mixed>
     *
     * @throws WahaRequestException
     */
    private function fetchContactsPage(ConnectionSnapshot $connection, int $perPage, int $offset): array
    {
        // Offset pagination against /api/contacts/all; a short page means
        // exhaustion per the target OpenAPI.
        return $this->client->listContactsOffset($connection, $perPage, $offset);
    }

    /**
     * @return array{chat_id: string, type: ContactType, name: ?string}|null
     */
    private function normalizeEntry(mixed $entry): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $chatId = null;

        foreach (['chatId', 'id', 'chat_id'] as $key) {
            if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
                $chatId = $entry[$key];

                break;
            }
        }

        if ($chatId === null) {
            return null;
        }

        if (preg_match('/^[0-9][0-9\-]*@g\.us$/i', $chatId) === 1) {
            $type = ContactType::Group;
        } elseif (preg_match('/^[0-9]+@c\.us$/i', $chatId) === 1) {
            $type = ContactType::Contact;
        } else {
            return null; // unsupported identifier shape (@lid etc.)
        }

        $name = null;
        foreach (['name', 'pushname', 'verifiedName'] as $key) {
            if (isset($entry[$key]) && is_string($entry[$key]) && trim($entry[$key]) !== '') {
                $name = trim($entry[$key]);

                break;
            }
        }

        return ['chat_id' => $chatId, 'type' => $type, 'name' => $name];
    }
}
