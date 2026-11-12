<?php

namespace App\Actions\Users;

use App\Access\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Platform\Access\AssignRole;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * An administrator invites a person (ADR-0027): the account is created with
 * a random password nobody knows and an unverified address, the role (if
 * any) is granted through AssignRole, and the person receives a link to set
 * their own password, which also verifies the address. They set up a
 * second factor on first sign-in, like everyone.
 */
class InviteUser
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, string $name, string $email, ?Role $role): User
    {
        $user = DB::transaction(function () use ($actor, $name, $email, $role): User {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => Str::password(64),
                'email_verified_at' => null,
            ])->save();

            $this->audit->record('user.invited', $user, $role === null ? [] : ['role' => $role->value], actor: $actor);

            if ($role !== null) {
                $this->assignRole->handle($actor, $user, $role);
            }

            return $user;
        }, 3);

        // After the commit: the link is for an account that exists.
        $user->notify(new UserInvited(Password::broker()->createToken($user), $actor->name));

        return $user;
    }
}
