<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('sends a user without a second factor to the security page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

it('answers 403 to API clients of a user without a second factor', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('dashboard'))
        ->assertForbidden()
        ->assertJson(['message' => 'Set up an authenticator app or a passkey to continue.']);
});

it('keeps enrolment, password confirmation and sign-out open', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($user)->get(route('password.confirm'))->assertOk();
    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
});

it('lets a user with a confirmed authenticator app through', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('lets a user with a passkey through', function () {
    $user = User::factory()->create();
    $user->passkeys()->forceCreate([
        'name' => 'Laptop',
        'credential_id' => 'credential-'.Str::random(16),
        'credential' => ['test' => true],
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

it('lets an unverified user reach e-mail verification first', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('can be switched off by an application', function () {
    config(['platform.identity.require_second_factor' => false]);

    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
});
