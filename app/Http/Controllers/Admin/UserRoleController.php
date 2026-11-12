<?php

namespace App\Http\Controllers\Admin;

use App\Access\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkRoleRequest;
use App\Models\User;
use App\Platform\Access\AssignRole;
use App\Platform\Access\RevokeRole;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Grants or revokes one role for the selected users (improvement #23). Each
 * user goes through AssignRole or RevokeRole on its own, so every rule
 * (locks, last administrator, no self-change, permission re-check, audit)
 * applies per user, and the result says who changed and who did not, why.
 */
class UserRoleController extends Controller
{
    public function __invoke(BulkRoleRequest $request, #[CurrentUser] User $actor, AssignRole $assign, RevokeRole $revoke): RedirectResponse
    {
        $role = Role::from($request->string('role')->toString());
        $revoking = $request->input('action') === 'revoke';
        /** @var list<int> $ids */
        $ids = array_map(intval(...), (array) $request->input('ids'));
        $users = User::query()->whereKey($ids)->get()->keyBy('id');

        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $user = $users->get($id);

            if ($user === null) {
                $failed[] = ['id' => $id, 'name' => null, 'reason' => __('This user no longer exists.')];

                continue;
            }

            try {
                $revoking ? $revoke->handle($actor, $user, $role) : $assign->handle($actor, $user, $role);
                $succeeded[] = $id;
            } catch (DomainRuleViolation $exception) {
                $failed[] = ['id' => $id, 'name' => $user->name, 'reason' => $exception->getMessage()];
            } catch (AuthorizationException) {
                $failed[] = ['id' => $id, 'name' => $user->name, 'reason' => __('You are not allowed to change roles.')];
            }
        }

        Inertia::flash('bulkResult', ['succeeded' => $succeeded, 'failed' => $failed]);
        Inertia::flash('toast', [
            'type' => $failed === [] ? 'success' : ($succeeded === [] ? 'error' : 'warning'),
            'message' => __(':done of :total updated.', ['done' => count($succeeded), 'total' => count($ids)]),
        ]);

        return back();
    }
}
