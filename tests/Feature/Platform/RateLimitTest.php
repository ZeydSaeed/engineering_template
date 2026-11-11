<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('locks one address after the per-minute limit', function () {
    config(['platform.security.rate_limits.login_per_minute' => 3]);
    User::factory()->create(['email' => 'person@example.test']);

    foreach (range(1, 3) as $attempt) {
        $this->post(route('login.store'), ['email' => 'person@example.test', 'password' => 'wrong-'.$attempt])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => 'person@example.test', 'password' => 'wrong-4'])->assertTooManyRequests();
});

it('limits one IP across many addresses per hour', function () {
    config(['platform.security.rate_limits.login_per_minute' => 100, 'platform.security.rate_limits.login_per_ip_per_hour' => 4]);

    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => "person{$attempt}@example.test", 'password' => 'x'])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), ['email' => 'person5@example.test', 'password' => 'x'])->assertTooManyRequests();
});
