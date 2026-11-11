<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case
|--------------------------------------------------------------------------
|
| Closure-based feature tests run inside the Laravel application. The
| class-based tests that came with the starter kit extend TestCase
| themselves. Architecture tests need no application.
|
*/

pest()->extend(TestCase::class)->in('Feature');

// Real commits, so other processes see the data: migrated afresh per test
// instead of wrapped in a transaction.
pest()->extend(TestCase::class)->use(DatabaseMigrations::class)->in('Concurrency');
