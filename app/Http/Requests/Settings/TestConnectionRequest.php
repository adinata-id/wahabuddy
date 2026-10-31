<?php

namespace App\Http\Requests\Settings;

use App\Rules\WahaBaseUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Connection tests run against possibly-unsaved form values; every field is
 * optional so an empty payload means "test what is stored".
 */
class TestConnectionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'base_url' => ['nullable', 'string', 'max:2048', new WahaBaseUrl],
            'session' => ['nullable', 'string', 'max:100', 'regex:/^[^\s\/]+$/'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'basic_username' => ['nullable', 'string', 'max:255'],
            'basic_password' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function messages(): array
    {
        return [
            'session.regex' => 'The session name must not contain whitespace or slashes.',
        ];
    }
}
