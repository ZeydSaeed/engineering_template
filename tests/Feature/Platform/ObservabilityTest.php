<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('splits server time into application and database time', function () {
    Route::get('/_test/timing', fn () => ['n' => count(DB::select('select 1')) + count(DB::select('select 1'))]);

    $header = (string) $this->get('/_test/timing')->assertOk()->headers->get('Server-Timing');

    expect($header)->toMatch('/^app;dur=\d+\.\d, db;dur=\d+\.\d;desc="2 queries"$/');
});

it('sends no timings when switched off', function () {
    config(['platform.observability.server_timing' => false]);

    $this->get('/up')->assertHeaderMissing('Server-Timing');
});

it('logs a slow statement with its SQL but never its values', function () {
    config(['platform.observability.slow_query_ms' => 100]);
    Log::spy();

    event(new QueryExecuted('select * from users where email = ?', ['person@example.test'], 250.0, DB::connection()));
    event(new QueryExecuted('select 1', [], 5.0, DB::connection()));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Slow database statement.'
        && $context['sql'] === 'select * from users where email = ?'
        && ! str_contains((string) json_encode($context), 'person@example.test'));
});
