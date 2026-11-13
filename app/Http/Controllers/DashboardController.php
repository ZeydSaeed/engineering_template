<?php

namespace App\Http\Controllers;

use App\Access\Permission;
use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Contracts\RequestsOverview;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\ActivityTimeline;
use App\Platform\Files\StoredFile;
use App\Platform\Preferences\UserPreference;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard as widgets each person can show, hide and order
 * (improvement #1, ADR-0029). A widget needing a permission exists only for
 * people who have it; a hidden widget's data is never computed.
 */
class DashboardController extends Controller
{
    public const PREFERENCE = 'dashboard:widgets';

    /** Widget => permission needed (null: everyone), in default order. */
    private const WIDGETS = [
        'notifications' => null,
        'activity' => null,
        'documents' => null,
        // From the Requests module, through its contract (ADR-0033).
        'requests' => null,
        'people' => Permission::RolesManage,
    ];

    public function __construct(private readonly RequestsOverview $requests) {}

    public function show(#[CurrentUser] User $user): Response
    {
        $layout = $this->layout($user);
        $visible = array_values(array_diff($layout['order'], $layout['hidden']));

        $data = [];
        foreach ($visible as $widget) {
            $data[$widget] = match ($widget) {
                // The relation is already newest first.
                'notifications' => $user->unreadNotifications()->limit(3)->get()
                    ->map(fn (DatabaseNotification $notification): array => [
                        'id' => $notification->id,
                        'data' => $notification->data,
                        'created_at' => $notification->created_at?->toIso8601String(),
                    ])->all(),
                'activity' => ActivityTimeline::for($user, includeActions: true, limit: 5),
                'documents' => StoredFile::query()
                    ->where('attachable_type', $user->getMorphClass())
                    ->where('attachable_id', $user->getKey())
                    ->toBase()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->map(fn (mixed $total): int => (int) $total)
                    ->all(),
                'requests' => $this->requests->for($user->id, $user->hasPermission(Permission::RequestsDecide)),
                'people' => [
                    'total' => User::query()->count(),
                    'without_second_factor' => User::query()->whereNull('two_factor_confirmed_at')->count(),
                    'administrators' => RoleAssignment::query()->where('role', Role::Administrator->value)->count(),
                ],
                default => null,
            };
        }

        return Inertia::render('dashboard', [
            'layout' => $layout,
            'widgets' => $data,
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate([
            'order' => ['present', 'array', 'max:20'],
            'order.*' => ['string', 'max:40'],
            'hidden' => ['present', 'array', 'max:20'],
            'hidden.*' => ['string', 'max:40'],
        ]);

        UserPreference::write($user->id, self::PREFERENCE, $this->normalise(
            $user,
            (array) $request->input('order'),
            (array) $request->input('hidden'),
        ));

        return to_route('dashboard');
    }

    /**
     * @return array{order: list<string>, hidden: list<string>, available: list<string>}
     */
    private function layout(User $user): array
    {
        $saved = UserPreference::read($user->id, self::PREFERENCE) ?? [];

        return [
            ...$this->normalise($user, (array) ($saved['order'] ?? []), (array) ($saved['hidden'] ?? [])),
            'available' => $this->available($user),
        ];
    }

    /**
     * Saved choices reconciled with the widgets this person may see: unknown
     * or forbidden widgets dropped, new ones added in their default place.
     *
     * @param  array<mixed>  $order
     * @param  array<mixed>  $hidden
     * @return array{order: list<string>, hidden: list<string>}
     */
    private function normalise(User $user, array $order, array $hidden): array
    {
        $available = $this->available($user);
        $ordered = array_values(array_unique(array_filter($order, fn (mixed $key): bool => is_string($key) && in_array($key, $available, true))));

        foreach ($available as $widget) {
            if (! in_array($widget, $ordered, true)) {
                $ordered[] = $widget;
            }
        }

        return [
            'order' => $ordered,
            'hidden' => array_values(array_unique(array_filter($hidden, fn (mixed $key): bool => is_string($key) && in_array($key, $available, true)))),
        ];
    }

    /**
     * @return list<string>
     */
    private function available(User $user): array
    {
        return array_keys(array_filter(
            self::WIDGETS,
            fn (?Permission $permission): bool => $permission === null || $user->hasPermission($permission),
        ));
    }
}
