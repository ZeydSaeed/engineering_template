<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Tables\SavedViewController;
use App\Http\Controllers\Tables\TableColumnsController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Component gallery: development and browser tests only (ADR-0025).
    if (app()->environment((array) config('platform.ui.gallery_environments'))) {
        Route::inertia('ui', 'dev/gallery')->name('ui.gallery');
    }

    // A person's table columns and saved views; each controller checks the
    // table's own ability (ADR-0026).
    Route::put('tables/{table}/columns', TableColumnsController::class)
        ->where('table', '[a-z-]+')
        ->name('tables.columns');
    Route::post('tables/{table}/views', [SavedViewController::class, 'store'])
        ->where('table', '[a-z-]+')
        ->name('tables.views.store');
    Route::delete('tables/{table}/views/{view}', [SavedViewController::class, 'destroy'])
        ->where(['table' => '[a-z-]+', 'view' => '[0-9]+'])
        ->name('tables.views.destroy');

    Route::middleware('can:roles.manage')->prefix('admin')->name('admin.')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::post('users/roles', UserRoleController::class)->name('users.roles');
        Route::get('users/invite', [UserInvitationController::class, 'create'])->name('users.invite');
        // Throttled before precognition: each step's check counts too.
        Route::post('users/invite', [UserInvitationController::class, 'store'])
            ->middleware(['throttle:30,1', HandlePrecognitiveRequests::class])
            ->name('users.invite.store');
    });
});

require __DIR__.'/settings.php';
