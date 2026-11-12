<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Saves a person's column order and hidden columns for one table (#3).
 * Only tables the person may see; only that table's columns.
 */
class TableColumnsController extends Controller
{
    public function __invoke(Request $request, string $table, TableRegistry $tables, #[CurrentUser] User $user): RedirectResponse
    {
        $entry = $tables->find($table) ?? abort(404);
        Gate::authorize($entry['ability']);

        $request->validate([
            'order' => ['present', 'array', 'max:50'],
            'order.*' => ['string', 'max:64'],
            'hidden' => ['present', 'array', 'max:50'],
            'hidden.*' => ['string', 'max:64'],
        ]);

        TableColumns::save($user->id, $entry['definition'], (array) $request->input('order'), (array) $request->input('hidden'));

        return back();
    }
}
