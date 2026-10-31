<?php

namespace App\Rules;

use App\Services\Waha\WahaUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates (and reports why) a WAHA base URL using the shared normalizer so
 * form validation and persistence agree on what a safe target is.
 */
class WahaBaseUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (WahaUrl::normalize((string) $value, config('wahabuddy.allow_private_networks', true)) === null) {
            $fail('The :attribute must be a valid http(s) URL without credentials, query, or fragment. Cloud metadata and link-local addresses are never allowed; private network targets depend on server configuration.');
        }
    }
}
