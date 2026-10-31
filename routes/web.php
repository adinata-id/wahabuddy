<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\StoryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('broadcasts', 'broadcasts/index')->name('broadcasts.index');
    Route::inertia('contacts', 'contacts/index')->name('contacts.index');

    Route::get('stories', [StoryController::class, 'index'])->name('stories.index');
    Route::get('stories/create', [StoryController::class, 'create'])->name('stories.create');
    Route::post('stories', [StoryController::class, 'store'])->middleware('throttle:30,1')->name('stories.store');
    Route::get('stories/{story}', [StoryController::class, 'show'])->name('stories.show');
    Route::get('stories/{story}/edit', [StoryController::class, 'edit'])->name('stories.edit');
    Route::put('stories/{story}', [StoryController::class, 'update'])->middleware('throttle:30,1')->name('stories.update');
    Route::delete('stories/{story}', [StoryController::class, 'destroy'])->middleware('throttle:30,1')->name('stories.destroy');

    Route::post('media', [MediaController::class, 'store'])->middleware('throttle:10,1')->name('media.store');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->middleware('throttle:20,1')->name('media.destroy');
    Route::post('media/test-storage', [MediaController::class, 'testStorage'])->middleware('throttle:5,1')->name('media.test-storage');

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
