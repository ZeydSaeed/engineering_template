<?php

namespace App\Http\Controllers\Tables;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Tables\SavedView;
use App\Platform\Tables\TableColumns;
use App\Platform\Tables\TableRegistry;
use App\Platform\Tables\TableState;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A person's named table views (#4). The state is checked against the
 * table's definition before it is stored; a name used again replaces that
 * view. Views belong to their owner alone: another person's view is 404.
 */
class SavedViewController extends Controller
{
    public function store(Request $request, string $table, TableRegistry $tables, #[CurrentUser] User $user): RedirectResponse
    {
        $entry = $tables->find($table) ?? abort(404);
        Gate::authorize($entry['ability']);
        $definition = $entry['definition'];

        $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'state' => ['required', 'array'],
            'columns' => ['nullable', 'array'],
        ]);

        $name = trim($request->string('name')->toString());
        /** @var array<mixed> $input */
        $input = (array) $request->input('state');
        $state = TableState::from($definition, [...$input, 'filter' => $input['filters'] ?? []])->toArray();
        /** @var array{order?: array<mixed>, hidden?: array<mixed>} $columns */
        $columns = (array) $request->input('columns', []);
        $state['columns'] = TableColumns::normalise($definition, (array) ($columns['order'] ?? []), (array) ($columns['hidden'] ?? []));

        $existing = SavedView::query()->where('user_id', $user->id)->where('table', $table);

        if (! (clone $existing)->where('name', $name)->exists() && (clone $existing)->count() >= SavedView::MAX_PER_TABLE) {
            throw ValidationException::withMessages(['name' => __('You can keep up to :max views for a table.', ['max' => SavedView::MAX_PER_TABLE])]);
        }

        SavedView::query()->updateOrCreate(
            ['user_id' => $user->id, 'table' => $table, 'name' => $name],
            ['state' => $state],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View saved.')]);

        return back();
    }

    public function destroy(string $table, int $view, TableRegistry $tables, #[CurrentUser] User $user): RedirectResponse
    {
        $entry = $tables->find($table) ?? abort(404);
        Gate::authorize($entry['ability']);

        SavedView::query()
            ->where('user_id', $user->id)
            ->where('table', $table)
            ->whereKey($view)
            ->firstOrFail()
            ->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('View deleted.')]);

        return back();
    }
}
