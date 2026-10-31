<?php

use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\SettingsController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('stories', 'stories/index')->name('stories.index');
    Route::inertia('broadcasts', 'broadcasts/index')->name('broadcasts.index');
    Route::inertia('contacts', 'contacts/index')->name('contacts.index');

    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings/connection', [ConnectionController::class, 'update'])
        ->middleware('throttle:10,1')->name('settings.connection.update');
    Route::post('settings/test-connection', [ConnectionController::class, 'test'])
        ->middleware('throttle:10,1')->name('settings.connection.test');
    Route::delete('settings/connection/api-key', [ConnectionController::class, 'clearApiKey'])
        ->middleware('throttle:6,1')->name('settings.connection.clear-api-key');
    Route::delete('settings/connection/basic-auth', [ConnectionController::class, 'clearBasicAuth'])
        ->middleware('throttle:6,1')->name('settings.connection.clear-basic-auth');
    Route::put('settings/preferences', [PreferencesController::class, 'update'])
        ->middleware('throttle:10,1')->name('settings.preferences.update');
});

require __DIR__.'/settings.php';
