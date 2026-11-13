<?php

use App\Modules\Requests\Http\AttachmentController;
use App\Modules\Requests\Http\RequestController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

/*
| Routes of the Requests module (ADR-0033), loaded by its provider. URLs
| carry the request's UUID, never its id.
*/

Route::middleware(['web', 'auth', 'verified'])->prefix('requests')->name('requests.')->group(function () {
    Route::get('/', [RequestController::class, 'index'])->name('index');
    Route::get('new', [RequestController::class, 'create'])->name('create');
    Route::post('/', [RequestController::class, 'store'])
        ->middleware([HandlePrecognitiveRequests::class, 'throttle:30,1'])
        ->name('store');

    Route::get('{approvalRequest}', [RequestController::class, 'show'])
        ->whereUuid('approvalRequest')
        ->name('show');
    Route::get('{approvalRequest}/edit', [RequestController::class, 'edit'])
        ->whereUuid('approvalRequest')
        ->name('edit');
    Route::put('{approvalRequest}', [RequestController::class, 'update'])
        ->middleware(HandlePrecognitiveRequests::class)
        ->whereUuid('approvalRequest')
        ->name('update');
    Route::post('{approvalRequest}/submit', [RequestController::class, 'submit'])
        ->whereUuid('approvalRequest')
        ->name('submit');
    Route::post('{approvalRequest}/decision', [RequestController::class, 'decide'])
        ->whereUuid('approvalRequest')
        ->name('decide');

    Route::post('{approvalRequest}/attachments', [AttachmentController::class, 'store'])
        ->middleware('throttle:20,1')
        ->whereUuid('approvalRequest')
        ->name('attachments.store');
    Route::get('{approvalRequest}/attachments/{file}', [AttachmentController::class, 'show'])
        ->whereUuid('file')
        ->whereUuid('approvalRequest')
        ->name('attachments.show');
});
