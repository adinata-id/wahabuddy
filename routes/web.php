<?php

use App\Http\Controllers\ContactsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\PreferencesController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\StoryActionsController;
use App\Http\Controllers\StoryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('scheduler/run', [DashboardController::class, 'runScheduler'])
        ->middleware('throttle:5,1')->name('scheduler.run');
    Route::inertia('broadcasts', 'broadcasts/index')->name('broadcasts.index');

    Route::get('contacts', [ContactsController::class, 'index'])->name('contacts.index');
    Route::post('contacts/sync', [ContactsController::class, 'sync'])
        ->middleware('throttle:3,1')->name('contacts.sync');
    Route::get('contacts/opted-in', [ContactsController::class, 'optedIn'])
        ->name('contacts.opted-in');
    Route::post('contacts/{contact}/consent', [ContactsController::class, 'setConsent'])
        ->middleware('throttle:60,1')->name('contacts.consent');

    Route::get('stories', [StoryController::class, 'index'])->name('stories.index');
    Route::get('stories/create', [StoryController::class, 'create'])->name('stories.create');
    Route::post('stories', [StoryController::class, 'store'])->middleware('throttle:30,1')->name('stories.store');
    Route::get('stories/{story}', [StoryController::class, 'show'])->name('stories.show');
    Route::get('stories/{story}/edit', [StoryController::class, 'edit'])->name('stories.edit');
    Route::put('stories/{story}', [StoryController::class, 'update'])->middleware('throttle:30,1')->name('stories.update');
    Route::delete('stories/{story}', [StoryController::class, 'destroy'])->middleware('throttle:30,1')->name('stories.destroy');

    Route::post('stories/{story}/retry', [StoryActionsController::class, 'retry'])
        ->middleware('throttle:20,1')->name('stories.retry');
    Route::post('stories/{story}/resolve', [StoryActionsController::class, 'resolve'])
        ->middleware('throttle:20,1')->name('stories.resolve');
    Route::post('stories/{story}/cancel', [StoryActionsController::class, 'cancel'])
        ->middleware('throttle:20,1')->name('stories.cancel');
    Route::post('stories/{story}/delete-remote', [StoryActionsController::class, 'deleteRemote'])
        ->middleware('throttle:10,1')->name('stories.delete-remote');

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
