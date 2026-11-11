<?php

namespace App\Rules;

use App\Services\Waha\WahaUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Storage endpoints share the WAHA host policy (spec 3.3): http(s) only, no
 * userinfo/query/fragment, cloud-metadata and link-local hosts always
 * rejected, private ranges gated by wahabuddy.allow_private_networks.
 */
final class StorageEndpointUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = WahaUrl::normalize(
            is_string($value) ? $value : null,
            (bool) config('wahabuddy.allow_private_networks', true),
        );

        if ($normalized === null) {
            $fail('The endpoint must be a valid http(s) URL and not target metadata or link-local addresses.');
        }
    }
}
