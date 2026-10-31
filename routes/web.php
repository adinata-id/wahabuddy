<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('stories', 'stories/index')->name('stories.index');
    Route::inertia('broadcasts', 'broadcasts/index')->name('broadcasts.index');
    Route::inertia('contacts', 'contacts/index')->name('contacts.index');
    Route::inertia('settings', 'settings/index')->name('settings.index');
});

require __DIR__.'/settings.php';
