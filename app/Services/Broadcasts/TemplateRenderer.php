<?php

namespace App\Services\Broadcasts;

/**
 * Renders broadcast templates. V1 supports exactly one placeholder — {name}
 * — substituted once (no evaluation, no recursive interpolation). Empty names
 * fall back to "there"; any other placeholder is rejected up front (spec I).
 */
final class TemplateRenderer
{
    public const NAME_PLACEHOLDER = '{name}';

    public const FALLBACK_NAME = 'there';

    /**
     * @throws \InvalidArgumentException when the template uses unsupported placeholders
     */
    public static function validate(string $template): void
    {
        $stripped = str_replace(self::NAME_PLACEHOLDER, '', $template);

        if (preg_match('/\{[^}]*\}/', $stripped) === 1) {
            throw new \InvalidArgumentException('Only the {name} placeholder is supported.');
        }
    }

    /**
     * Single-pass substitution; a literal "{name}" produced by a contact's
     * name is never re-expanded.
     */
    public static function render(string $template, ?string $name): string
    {
        $replacement = ($name === null || trim($name) === '') ? self::FALLBACK_NAME : $name;

        return str_replace(self::NAME_PLACEHOLDER, $replacement, $template);
    }
}
