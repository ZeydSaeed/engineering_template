<?php

use App\Access\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| Inviting a person (#6; ADR-0027): administrators only; each step checked
| with the final rules; the account starts without a usable password and
| with an unverified address; the invitation link sets the password and
| verifies the address.
*/

uses(RefreshDatabase::class);

function inviter(): User
{
    $user = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

it('is for administrators only', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)->get('/admin/users/invite')->assertForbidden();
    $this->actingAs($user)->post('/admin/users/invite', ['name' => 'X', 'email' => 'x@example.test'])->assertForbidden();
});

it('checks one step at a time with the final rules, without creating anything', function () {
    $admin = inviter();
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($admin)
        ->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'name,email'])
        ->postJson('/admin/users/invite', ['name' => 'New', 'email' => 'TAKEN@example.test'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $this->actingAs($admin)
        ->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'role'])
        ->postJson('/admin/users/invite', ['role' => 'superuser'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    expect(User::query()->count())->toBe(2);
});

it('creates the account, grants the role, records it and sends the invitation', function () {
    Notification::fake();
    $admin = inviter();

    $this->actingAs($admin)
        ->post('/admin/users/invite', ['name' => 'Huda', 'email' => ' Huda@Example.test ', 'role' => 'auditor'])
        ->assertRedirect('/admin/users');

    $user = User::query()->where('email', 'huda@example.test')->sole();
    expect($user->email_verified_at)->toBeNull()
        ->and($user->hasRole(Role::Auditor))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'user.invited')->where('subject_id', (string) $user->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'access.role_assigned')->exists())->toBeTrue();

    Notification::assertSentTo($user, UserInvited::class);
});

it('lets the invited person set a password, which verifies the address', function () {
    Notification::fake();
    $admin = inviter();

    $this->actingAs($admin)->post('/admin/users/invite', ['name' => 'Omar', 'email' => 'omar@example.test']);
    $user = User::query()->where('email', 'omar@example.test')->sole();

    $token = null;
    Notification::assertSentTo($user, UserInvited::class, function (UserInvited $notification) use ($user, &$token) {
        $url = $notification->toMail($user)->actionUrl;
        $token = basename((string) parse_url($url, PHP_URL_PATH));

        return str_contains($url, 'email=omar%40example.test');
    });

    auth()->logout();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'omar@example.test',
        'password' => 'A-long-new-password-2026',
        'password_confirmation' => 'A-long-new-password-2026',
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect(Hash::check('A-long-new-password-2026', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});
