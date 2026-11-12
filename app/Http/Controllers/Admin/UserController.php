<?php

namespace App\Http\Controllers\Admin;

use App\Access\Permission;
use App\Access\Role;
use App\Http\Controllers\Controller;
use App\Http\Tables\UsersTable;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\ActivityTimeline;
use App\Platform\Tables\SavedView;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users for administrators (roles.manage): a server-side table (#2) with the
 * person's columns (#3) and saved views (#4); one user's details and history
 * in a side panel (#16, #9), loaded only when the panel opens.
 */
class UserController extends Controller
{
    public function index(Request $request, UsersTable $table, #[CurrentUser] User $viewer): Response
    {
        $state = TableState::from($table, $request->query());
        $users = $state->paginate($table, User::query()->with('roleAssignments'));

        return Inertia::render('admin/users/index', [
            'users' => [
                'rows' => $users->getCollection()->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roleAssignments->pluck('role')->sort()->values()->all(),
                    'two_factor' => $user->two_factor_confirmed_at !== null,
                    'created_at' => $user->created_at?->toIso8601String(),
                ])->all(),
                'page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'state' => $state->toArray(),
            'columns' => TableColumns::for($viewer->id, $table),
            'filterOptions' => $table->filters(),
            'roles' => array_map(fn (Role $role): string => $role->value, Role::cases()),
            'views' => SavedView::query()
                ->where('user_id', $viewer->id)
                ->where('table', $table->name())
                ->orderBy('name')
                ->get(['id', 'name', 'state'])
                ->map(fn (SavedView $view): array => ['id' => $view->id, 'name' => $view->name, 'state' => $view->state])
                ->all(),
            // Only when a user is opened (?user=ID or a partial reload).
            'selected' => fn (): ?array => $this->details($request->integer('user'), $viewer),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function details(int $id, User $viewer): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $user = User::query()->with('roleAssignments')->find($id);

        if ($user === null) {
            return null;
        }

        $canSeeHistory = $viewer->hasPermission(Permission::AuditView);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
            'roles' => $user->roleAssignments->map(fn (RoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'since' => $assignment->created_at->toIso8601String(),
            ])->values()->all(),
            'is_viewer' => $user->is($viewer),
            'timeline' => $canSeeHistory ? ActivityTimeline::for($user, includeActions: true) : null,
        ];
    }
}
