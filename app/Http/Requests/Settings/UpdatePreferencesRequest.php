<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            // Pacing invariants (spec I): min>=1, max>=min, batch>=1, pause>=1.
            'broadcast_delay_min' => ['required', 'integer', 'min:1', 'max:3600'],
            'broadcast_delay_max' => ['required', 'integer', 'min:1', 'max:3600', 'gte:broadcast_delay_min'],
            'broadcast_batch_size' => ['required', 'integer', 'min:1', 'max:1000'],
            'broadcast_batch_pause' => ['required', 'integer', 'min:1', 'max:86400'],
        ];
    }
}
