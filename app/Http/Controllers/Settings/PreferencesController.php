<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePreferencesRequest;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;

class PreferencesController extends Controller
{
    public function update(UpdatePreferencesRequest $request): RedirectResponse
    {
        AppSetting::singleton()->fill($request->validated())->save();

        return back()->with('status', 'preferences-updated');
    }
}
