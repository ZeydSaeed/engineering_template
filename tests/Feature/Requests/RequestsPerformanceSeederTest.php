<?php

use Database\Seeders\RequestsPerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The load-test seeder writes synthetic requests and makes approvers; it
| must never run anywhere but locally on the development database.
*/

uses(RefreshDatabase::class);

it('refuses to run outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse();

    $this->seed(RequestsPerformanceSeeder::class);
})->throws(RuntimeException::class, 'runs only locally');

it('refuses to run on any database but the development one, even locally', function () {
    app()->detectEnvironment(fn (): string => 'local');
    config(['platform.performance.database' => 'some_other_database']);

    $this->seed(RequestsPerformanceSeeder::class);
})->throws(RuntimeException::class, 'runs only locally');
