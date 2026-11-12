<?php

namespace App\Http\Controllers\Admin;

use App\Access\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Interactive reports (improvement #21, ADR-0029), for people who may read
 * the audit trail (audit.view): sign-ins per day over a chosen period, and
 * people by role. Days without sign-ins are reported as zero.
 */
class ReportController extends Controller
{
    public const PERIODS = [7, 30, 90];

    public function __invoke(Request $request): Response
    {
        $days = (int) $request->integer('days', 30);
        $days = in_array($days, self::PERIODS, true) ? $days : 30;

        return Inertia::render('admin/reports', [
            'days' => $days,
            'periods' => self::PERIODS,
            'signIns' => $this->signIns($days),
            'roles' => $this->roles(),
        ]);
    }

    /**
     * @return list<array{day: string, count: int}>
     */
    private function signIns(int $days): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays($days - 1);

        $query = AuditEvent::query()
            ->where('action', 'auth.login')
            ->where('occurred_at', '>=', $from);

        // A UTC calendar day; the expression is fixed text, never input.
        $day = $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? "to_char(occurred_at at time zone 'UTC', 'YYYY-MM-DD')"
            : 'substr(occurred_at, 1, 10)';

        $counts = $query->toBase()
            ->selectRaw("{$day} as day, count(*) as total")
            ->groupByRaw($day)
            ->pluck('total', 'day')
            ->all();

        $series = [];

        for ($date = $from; $date <= $today; $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $series[] = ['day' => $key, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $series;
    }

    /**
     * @return list<array{role: string, count: int}>
     */
    private function roles(): array
    {
        $counts = RoleAssignment::query()
            ->toBase()
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role')
            ->all();

        $rows = array_map(
            fn (Role $role): array => ['role' => $role->value, 'count' => (int) ($counts[$role->value] ?? 0)],
            Role::cases(),
        );

        $rows[] = ['role' => 'none', 'count' => User::query()->whereDoesntHave('roleAssignments')->count()];

        return $rows;
    }
}
