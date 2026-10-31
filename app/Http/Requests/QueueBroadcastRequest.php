<?php

namespace App\Http\Requests;

use App\Services\Broadcasts\RecipientInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Recipient review payload for queueing: entries either come from the
 * opted-in contact selection or from normalized pasted numbers — both are
 * re-validated server-side (spec I: no trusting browser-side filtering).
 */
class QueueBroadcastRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'recipients' => ['required', 'array', 'min:1', 'max:2000'],
            'recipients.*.chat_id' => ['required', 'string', 'max:120'],
            'recipients.*.name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $valid = 0;

            foreach ((array) $this->input('recipients', []) as $index => $recipient) {
                $entry = is_array($recipient) ? (string) ($recipient['chat_id'] ?? '') : '';

                if (RecipientInput::normalize($entry) !== null) {
                    $valid++;
                } else {
                    $validator->errors()->add("recipients.{$index}.chat_id", "The recipient [{$entry}] is not a valid international number or group id.");
                }
            }

            if ($valid === 0) {
                $validator->errors()->add('recipients', 'At least one valid recipient is required.');
            }
        });
    }
}
