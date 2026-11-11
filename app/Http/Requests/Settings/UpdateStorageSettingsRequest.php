<?php

namespace App\Http\Requests\Settings;

use App\Rules\StorageEndpointUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStorageSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            // Blank secret keeps the stored value; blank non-secret fields
            // clear it (spec 3.3 per-field semantics).
            'key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret' => ['sometimes', 'nullable', 'string', 'max:512'],
            'bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:64'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:255', new StorageEndpointUrl],
            'use_path_style_endpoint' => ['sometimes', 'boolean'],
        ];
    }
}
