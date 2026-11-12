<?php

namespace App\Http\Controllers;

use App\Access\Permission;
use App\Http\Tables\UsersTable;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records for the global search (improvement #5, ADR-0028). Only what the
 * person may see: users for those who manage roles. Pages are searched in
 * the browser from the navigation they already have. A few results per
 * kind; the full lists have their own search.
 */
class SearchController extends Controller
{
    public const LIMIT = 5;

    public function __invoke(Request $request, UsersTable $users, #[CurrentUser] User $viewer): JsonResponse
    {
        $query = trim((string) $request->string('q'));

        if (mb_strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $query = mb_substr($query, 0, 100);
        $found = [];

        if ($viewer->hasPermission(Permission::RolesManage)) {
            $builder = User::query()->orderBy('name')->limit(self::LIMIT);
            $users->applySearch($builder, $query);

            $found = $builder->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'url' => route('admin.users.index', ['search' => $user->email, 'user' => $user->id], false),
                ])
                ->all();
        }

        return response()->json(['users' => $found]);
    }
}
