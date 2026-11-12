<?php

use App\Http\Controllers\Settings\DocumentController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // Precognitive: the form checks fields against these rules while typing.
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(HandlePrecognitiveRequests::class)
        ->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    // Throttled before precognition, so live checks count against the
    // same limit as submissions.
    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware(['throttle:6,1', HandlePrecognitiveRequests::class])
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    // A person's own documents (ADR-0020, ADR-0027).
    Route::get('settings/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('settings/documents', [DocumentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('documents.store');
    Route::get('settings/documents/{uuid}', [DocumentController::class, 'show'])
        ->whereUuid('uuid')
        ->name('documents.show');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
