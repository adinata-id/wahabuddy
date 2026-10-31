<?php

namespace App\Http\Requests\Settings;

use App\Models\AppSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

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

            // Storage & cleanup. S3 is only selectable once its adapter
            // package and credentials are actually present.
            'media_disk' => ['sometimes', 'required', 'string', Rule::in($this->availableMediaDisks())],
            // Public base WAHA downloads local media from (e.g. a tunnel);
            // null falls back to APP_URL. Only send URLs use it.
            'media_public_base_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'media_cleanup_enabled' => ['sometimes', 'required', 'boolean'],
            'media_cleanup_frequency' => ['sometimes', 'required', 'string', 'in:daily,weekly,monthly,custom'],
            // 0 (Sunday) - 6 (Saturday) for weekly; 1-28 for monthly.
            'media_cleanup_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:28'],
            'media_cleanup_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'media_cleanup_retention_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:90'],
            'media_cleanup_include_terminal' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'media_disk.in' => 'S3 storage needs the S3 adapter package and configured credentials; only the local disk is available.',
            'media_public_base_url.url' => 'Enter a valid http(s) URL, e.g. https://your-tunnel.example.com.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('media_public_base_url')) {
            // Stored trimmed, without a trailing slash (so the resolver can
            // concatenate); an emptied field clears back to null.
            $value = trim((string) $this->input('media_public_base_url'));

            $this->merge([
                'media_public_base_url' => $value === '' ? null : rtrim($value, '/'),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['media_cleanup_frequency', 'media_disk'])) {
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

    /**
     * Disks an operator may actually select: the local public disk always,
     * S3 only with the adapter package installed and credentials present
     * (server-side gate; the UI probes reachability before enabling it).
     *
     * @return list<string>
     */
    private function availableMediaDisks(): array
    {
        $disks = ['public'];

        $s3Ready = class_exists(AwsS3V3Adapter::class)
            && filled(config('filesystems.disks.s3.key'))
            && filled(config('filesystems.disks.s3.secret'))
            && filled(config('filesystems.disks.s3.bucket'));

        if ($s3Ready) {
            $disks[] = 's3';
        }

        return $disks;
    }
}
