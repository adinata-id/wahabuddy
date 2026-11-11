<?php

namespace App\Http\Requests\Settings;

use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePreferencesRequest extends FormRequest
{
    /**
     * Every rule is "sometimes": each settings card saves its own slice of
     * the singleton row without having to submit the other cards' fields.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'display_timezone' => ['sometimes', 'required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            // Pacing invariants (spec I): min>=1, max>=min, batch>=1, pause>=1.
            'broadcast_delay_min' => ['sometimes', 'required', 'integer', 'min:1', 'max:3600'],
            'broadcast_delay_max' => ['sometimes', 'required', 'integer', 'min:1', 'max:3600', 'gte:broadcast_delay_min'],
            'broadcast_batch_size' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
            'broadcast_batch_pause' => ['sometimes', 'required', 'integer', 'min:1', 'max:86400'],

            // Storage & cleanup. Uploads are S3-only; there is no storage
            // disk or public base URL setting anymore.
            'media_cleanup_enabled' => ['sometimes', 'required', 'boolean'],
            'media_cleanup_frequency' => ['sometimes', 'required', 'string', 'in:daily,weekly,monthly,custom'],
            // 0 (Sunday) - 6 (Saturday) for weekly; 1-28 for monthly.
            'media_cleanup_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:28'],
            'media_cleanup_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'media_cleanup_retention_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:90'],
            'media_cleanup_include_terminal' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media_cleanup_frequency')) {
                return;
            }

            // Partial saves are allowed, so the spec-I pacing invariant
            // max >= min must also hold against the values already stored,
            // not only against fields present in this request.
            if (! $validator->errors()->hasAny(['broadcast_delay_min', 'broadcast_delay_max'])) {
                $settings = AppSetting::singleton();
                // integer() defaults to 0 for absent keys, so presence is
                // checked explicitly before falling back to the stored value.
                $min = $this->has('broadcast_delay_min') ? (int) $this->input('broadcast_delay_min') : (int) $settings->broadcast_delay_min;
                $max = $this->has('broadcast_delay_max') ? (int) $this->input('broadcast_delay_max') : (int) $settings->broadcast_delay_max;

                if ($max < $min) {
                    $validator->errors()->add('broadcast_delay_max', 'Max delay must be greater than or equal to min delay.');
                }
            }

            $frequency = $this->input('media_cleanup_frequency');
            $day = $this->input('media_cleanup_day');

            if ($frequency === 'weekly' && ($day === null || (int) $day < 0 || (int) $day > 6)) {
                $validator->errors()->add('media_cleanup_day', 'Pick a weekday for the weekly schedule.');
            }

            if ($frequency === 'monthly' && ($day === null || (int) $day < 1 || (int) $day > 28)) {
                $validator->errors()->add('media_cleanup_day', 'Pick a day of the month between 1 and 28.');
            }

            if ($frequency === 'custom' && $this->filled('media_cleanup_interval_days') === false) {
                $validator->errors()->add('media_cleanup_interval_days', 'Enter how many days should pass between cleanups.');
            }
        });
    }
}
