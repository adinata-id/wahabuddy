<?php

namespace App\Http\Requests;

use App\Enums\BroadcastType;
use App\Models\MediaFile;
use App\Services\Broadcasts\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBroadcastRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(BroadcastType::class)],
            'template_text' => ['required', 'string', 'max:4096'],
            'media_file_id' => ['nullable', 'integer', Rule::exists('media_files', 'id')->whereNull('purged_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                TemplateRenderer::validate((string) $this->input('template_text', ''));
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('template_text', $exception->getMessage());
            }

            if ($this->input('type') === BroadcastType::Image->value) {
                $mediaId = $this->input('media_file_id');

                $media = $mediaId !== null ? MediaFile::find((int) $mediaId) : null;

                if ($media === null || $media->purged_at !== null || ! str_starts_with($media->mime_type, 'image/')) {
                    $validator->errors()->add('media_file_id', 'Image broadcasts need uploaded image media.');
                }
            }
        });
    }
}
