<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Synthetic requests for the local load test of the Requests module
 * (tests/load/requests.js, ADR-0033), on top of PerformanceSeeder's users.
 * perf-51 to perf-60 become approvers; perf-61 onwards are requesters.
 * Refuses to run outside the local environment or on any database but the
 * development one; running it again adds nothing.
 *
 *   PERF_REQUESTS=10000 php artisan db:seed --class=RequestsPerformanceSeeder
 */
class RequestsPerformanceSeeder extends Seeder
{
    public const TITLE = 'Perf request';

    public function run(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $allowed = (string) config('platform.performance.database');

        if (! app()->environment('local') || $database !== $allowed) {
            throw new RuntimeException("RequestsPerformanceSeeder runs only locally on [{$allowed}], not on [{$database}].");
        }

        $users = User::query()->where('email', 'like', 'perf-%')->orderBy('id')->pluck('id')->all();

        if (count($users) < 100) {
            throw new RuntimeException('Run PerformanceSeeder first: at least 100 perf users are needed.');
        }

        $approvers = array_slice($users, 50, 10);
        $requesters = array_slice($users, 60);

        DB::table('role_assignments')->insertOrIgnore(array_map(
            fn (int $id): array => ['user_id' => $id, 'role' => Role::Approver->value],
            $approvers,
        ));

        if (DB::table('approval_requests')->where('title', 'like', self::TITLE.' %')->exists()) {
            $this->command?->info('Requests already seeded; approvers checked.');

            return;
        }

        $count = (int) config('platform.performance.requests');
        $this->command?->info("Seeding {$count} requests from ".count($requesters).' requesters into '.$database.'.');

        $now = CarbonImmutable::now('UTC');
        $statuses = RequestStatus::cases();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $status = $statuses[$i % 4];
            $created = $now->subMinutes(($count - $i) * 5);
            $submitted = $status === RequestStatus::Draft ? null : $created->addMinutes(3);
            $decided = $status->isFinal() ? $created->addMinutes(7) : null;

            $rows[] = [
                'uuid' => (string) Str::uuid7(),
                'requester_id' => $requesters[$i % count($requesters)],
                'title' => self::TITLE." {$i}",
                'details' => 'Synthetic request for the load test.',
                'amount' => $i % 3 === 0 ? null : number_format(($i % 997) * 1.25, 2, '.', ''),
                'status' => $status->value,
                'submitted_at' => $submitted,
                'decided_by' => $decided === null ? null : $approvers[$i % count($approvers)],
                'decided_at' => $decided,
                'decision_note' => $status === RequestStatus::Rejected ? 'Not this quarter.' : null,
                'lock_version' => 1,
                'created_at' => $created,
                'updated_at' => $decided ?? $submitted ?? $created,
            ];

            if (count($rows) === 1000) {
                DB::table('approval_requests')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('approval_requests')->insert($rows);
        }

        DB::statement('analyze approval_requests');
    }
}
