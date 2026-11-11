<?php

namespace App\Http\Requests\Settings;

/**
 * Probe payload for possibly-unsaved S3 form values (spec D2): identical
 * rules to the save request minus the enabled toggle, which the probe
 * does not use.
 */
class TestStorageSettingsRequest extends UpdateStorageSettingsRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['enabled']);

        return $rules;
    }
}
