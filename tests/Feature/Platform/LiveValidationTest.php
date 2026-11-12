<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/*
| Live validation (improvement #7): forms ask the server to check fields with
| the same rules a submission uses (Laravel Precognition). A check never
| changes anything, and on the password route it counts against the same
| rate limit as a submission.
*/

uses(RefreshDatabase::class);

function precognitionHeaders(string $only): array
{
    return ['Precognition' => 'true', 'Precognition-Validate-Only' => $only];
}

it('reports an invalid field with the server rules and saves nothing', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'before@example.test']);

    $this->actingAs($user)
        ->withHeaders(precognitionHeaders('email'))
        ->patchJson(route('profile.update'), ['name' => 'Someone', 'email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect($user->refresh()->email)->toBe('before@example.test');
});

it('accepts a valid field without saving it', function () {
    $user = User::factory()->withTwoFactor()->create(['name' => 'Before']);

    $this->actingAs($user)
        ->withHeaders(precognitionHeaders('name'))
        ->patchJson(route('profile.update'), ['name' => 'After', 'email' => $user->email])
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');

    expect($user->refresh()->name)->toBe('Before');
});

it('checks only the requested field', function () {
    $user = User::factory()->withTwoFactor()->create();

    // The email is invalid, but only the name was asked about.
    $this->actingAs($user)
        ->withHeaders(precognitionHeaders('name'))
        ->patchJson(route('profile.update'), ['name' => 'Fine', 'email' => 'not-an-email'])
        ->assertNoContent();
});

it('never changes the password from a live check', function () {
    $user = User::factory()->withTwoFactor()->create(['password' => Hash::make('Old-password-123!')]);

    $this->actingAs($user)
        ->withHeaders(precognitionHeaders('password,password_confirmation,current_password'))
        ->putJson(route('user-password.update'), [
            'current_password' => 'Old-password-123!',
            'password' => 'New-password-456!long',
            'password_confirmation' => 'New-password-456!long',
        ])
        ->assertNoContent();

    expect(Hash::check('Old-password-123!', $user->refresh()->password))->toBeTrue();
});

it('counts live checks against the password route limit', function () {
    $user = User::factory()->withTwoFactor()->create();

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($user)
            ->withHeaders(precognitionHeaders('current_password'))
            ->putJson(route('user-password.update'), ['current_password' => "guess-{$i}"])
            ->assertStatus(422);
    }

    $this->actingAs($user)
        ->withHeaders(precognitionHeaders('current_password'))
        ->putJson(route('user-password.update'), ['current_password' => 'guess-7'])
        ->assertStatus(429);
});
