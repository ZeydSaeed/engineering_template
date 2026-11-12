<?php

namespace App\Http\Controllers\Admin;

use App\Access\Role;
use App\Actions\Users\InviteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inviting a person, as a multi-step form (improvement #6): details, role,
 * review. Administrators only (roles.manage). ADR-0027.
 */
class UserInvitationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('admin/users/invite', [
            'roles' => array_map(fn (Role $role): string => $role->value, Role::cases()),
        ]);
    }

    public function store(InviteUserRequest $request, InviteUser $invite, #[CurrentUser] User $actor): RedirectResponse
    {
        $role = $request->filled('role') ? Role::from($request->string('role')->toString()) : null;
        $user = $invite->handle($actor, $request->string('name')->toString(), $request->string('email')->toString(), $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $user->email])]);

        return to_route('admin.users.index');
    }
}
