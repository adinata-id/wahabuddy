<?php

namespace App\Services\Waha;

use App\Enums\Capability;
use App\Models\WahaConnection;

/**
 * Resolves WAHA feature capabilities as supported|unsupported|unknown.
 *
 * There is no capability-discovery endpoint to rely on: the base truth is the
 * documented engine/edition matrix; a stored override (admin-confirmed or
 * identified from version/engine info) always wins. "Unknown" is never turned
 * into "supported" by guessing, and sends are never used as probes (spec D2).
 */
final class CapabilityResolver
{
    /** Capability keys tracked by v1. */
    public const KEYS = [
        'text_status',
        'image_status',
        'video_status',
        'delete_status',
        'video_convert',
        'contacts',
        'chats',
    ];

    /**
     * Documented baseline per the official WAHA docs matrix (2026-10-31):
     * text/image status and contact/chat listing are core features; video
     * story support, deletion, and server-side conversion vary by
     * engine/edition and stay unknown until identified.
     */
    private const DOCUMENTED_BASELINE = [
        'text_status' => [Capability::Supported, 'docs'],
        'image_status' => [Capability::Supported, 'docs'],
        'video_status' => [Capability::Unknown, 'docs'],
        'delete_status' => [Capability::Unknown, 'docs'],
        'video_convert' => [Capability::Unknown, 'docs'],
        'contacts' => [Capability::Supported, 'docs'],
        'chats' => [Capability::Supported, 'docs'],
    ];

    /**
     * @return array<string, array{state: string, source: string}>
     */
    public function overview(?WahaConnection $connection = null): array
    {
        $connection ??= app(ConnectionRepository::class)->current();
        $stored = $connection->capabilities ?? [];

        $overview = [];
        foreach (self::DOCUMENTED_BASELINE as $key => [$state, $source]) {
            $override = $stored[$key] ?? null;

            if (is_array($override)
                && isset($override['state'])
                && ($case = Capability::tryFrom((string) $override['state'])) !== null
            ) {
                $overview[$key] = [
                    'state' => $case->value,
                    'source' => (string) ($override['source'] ?? 'admin'),
                ];

                continue;
            }

            $overview[$key] = ['state' => $state->value, 'source' => $source];
        }

        return $overview;
    }
}
