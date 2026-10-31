<?php

namespace App\Http\Requests\Settings;

use App\Rules\WahaBaseUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConnectionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Blank/omitted secret fields keep the stored value (spec E).
            'base_url' => ['nullable', 'string', 'max:2048', new WahaBaseUrl],
            'session' => ['required', 'string', 'max:100', 'regex:/^[^\s\/]+$/'],
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
