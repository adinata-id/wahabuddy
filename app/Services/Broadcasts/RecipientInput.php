<?php

namespace App\Services\Broadcasts;

/**
 * Normalizes pasted recipient input (spec I): international numbers with an
 * optional leading +, no country guessing — local formats are rejected with
 * an explanation. WhatsApp group ids are preserved verbatim; @lid identifiers
 * are rejected because the adapter contract has not been verified for them.
 */
final class RecipientInput
{
    /**
     * @return array{valid: array<int, array{chat_id: string, type: 'contact'|'group'}>, invalid: array<int, string>}
     */
    public static function parse(string $raw): array
    {
        $valid = [];
        $invalid = [];
        $seen = [];

        $lines = preg_split('/\r\n|\r|\n|[;,]/', trim($raw)) ?: [];

        foreach ($lines as $line) {
            $entry = trim($line);

            if ($entry === '') {
                continue;
            }

            $normalized = self::normalize($entry);

            if ($normalized === null) {
                $invalid[] = $entry;

                continue;
            }

            if (isset($seen[$normalized['chat_id']])) {
                continue; // deduplicate after normalization
            }

            $seen[$normalized['chat_id']] = true;
            $valid[] = $normalized;
        }

        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * @return array{chat_id: string, type: 'contact'|'group'}|null
     */
    public static function normalize(string $entry): ?array
    {
        $entry = trim($entry);

        if ($entry === '') {
            return null;
        }

        // Group ids pass through unchanged.
        if (preg_match('/^[0-9][0-9\-]*@g\.us$/i', $entry) === 1) {
            return ['chat_id' => $entry, 'type' => 'group'];
        }

        // @lid identifiers: reject clearly instead of mis-parsing (spec I).
        if (str_contains($entry, '@')) {
            return null;
        }

        // A phone number is digits with an optional leading + and separators.
        $clean = ltrim(str_replace([' ', '-', '(', ')'], '', $entry), '+');

        if ($clean === '' || ! ctype_digit($clean)) {
            return null;
        }

        // Local numbers (leading zero, or too short/long for E.164 without a
        // country code) are rejected: guessing the country is not allowed.
        if (str_starts_with($clean, '0')) {
            return null;
        }

        $length = strlen($clean);

        if ($length < 8 || $length > 15) {
            return null;
        }

        return ['chat_id' => $clean.'@c.us', 'type' => 'contact'];
    }
}
