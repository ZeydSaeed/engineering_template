<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Component gallery: development and browser tests only (ADR-0025).
    if (app()->environment((array) config('platform.ui.gallery_environments'))) {
        Route::inertia('ui', 'dev/gallery')->name('ui.gallery');
    }
});

require __DIR__.'/settings.php';
