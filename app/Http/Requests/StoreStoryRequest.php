<?php

namespace App\Http\Requests;

use App\Enums\StoryType;
use App\Models\MediaFile;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for creating/editing a story. Product limits (spec J): text
 * stories max 700 chars, media captions max 500. Scheduled times must carry
 * an explicit offset or an explicit timezone — naive server-timezone
 * interpretation is never allowed (spec G2).
 */
class StoreStoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(StoryType::class)],
            'text' => ['nullable', 'string'],
            'background_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font' => ['nullable', 'integer', 'between:0,6'],
            'media_file_id' => ['nullable', 'integer', Rule::exists('media_files', 'id')->whereNull('purged_at')],
            'scheduled_at' => ['required', 'string', 'max:64'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $text = (string) $this->input('text', '');

            if ($type === StoryType::Text->value) {
                if (trim($text) === '') {
                    $validator->errors()->add('text', 'A text story requires message text.');
                } elseif (mb_strlen($text) > 700) {
                    $validator->errors()->add('text', 'Text stories are limited to 700 characters.');
                }
            } else {
                if (mb_strlen($text) > 500) {
                    $validator->errors()->add('text', 'Captions are limited to 500 characters.');
                }

                $mediaId = $this->input('media_file_id');

                if ($mediaId === null) {
                    $validator->errors()->add('media_file_id', 'Image and video stories require uploaded media.');

                    return;
                }

                $media = MediaFile::find((int) $mediaId);

                if ($media === null || $media->purged_at !== null) {
                    $validator->errors()->add('media_file_id', 'The selected media no longer exists.');

                    return;
                }

                $expected = $type === StoryType::Video->value ? 'video/' : 'image/';

                if (! str_starts_with($media->mime_type, $expected)) {
                    $validator->errors()->add('media_file_id', "The selected media is not {$expected} content.");
                }
            }

            if ($this->parseScheduledAt() === null) {
                $validator->errors()->add('scheduled_at', 'Provide a scheduled time with an explicit UTC offset or a timezone for conversion.');
            } elseif ($this->parseScheduledAt()->isPast()) {
                $validator->errors()->add('scheduled_at', 'The scheduled time must be in the future.');
            }
        });
    }

    /**
     * Resolve the schedule to a UTC Carbon, or null when it cannot be
     * interpreted safely.
     */
    public function parseScheduledAt(): ?Carbon
    {
        $value = (string) $this->input('scheduled_at', '');

        try {
            // ISO 8601 with offset (or Z) — exact instant.
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value)) {
                return Carbon::parse($value)->utc();
            }

            // Naive wall-clock time plus an explicit timezone to convert from.
            $timezone = $this->input('timezone');

            if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                return Carbon::parse($value, $timezone)->utc();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
