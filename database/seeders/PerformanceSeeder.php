<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Platform\Audit\AuditChain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * Synthetic data for local load tests (docs/operations/performance-testing.md).
 * Refuses to run outside the local environment or on any database but the
 * development one. Every user gets their own authenticator secret, written
 * with the e-mail to storage/app/private/perf/users.csv for the k6 scripts.
 *
 *   PERF_USERS=1000 PERF_AUDIT_EVENTS=50000 php artisan db:seed --class=PerformanceSeeder
 */
class PerformanceSeeder extends Seeder
{
    public const PASSWORD = 'Perf-Passw0rd!long';

    public function run(Google2FA $google2fa): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $allowed = (string) config('platform.performance.database');

        if (! app()->environment('local') || $database !== $allowed) {
            throw new RuntimeException("PerformanceSeeder runs only locally on [{$allowed}], not on [{$database}].");
        }

        $users = (int) config('platform.performance.users');
        $events = (int) config('platform.performance.audit_events');

        $this->command?->info("Seeding {$users} users and {$events} audit events into {$database}.");

        $password = Hash::make(self::PASSWORD);
        $now = CarbonImmutable::now('UTC');
        $csv = ['email,secret'];
        $rows = [];

        for ($i = 1; $i <= $users; $i++) {
            $secret = $google2fa->generateSecretKey(32);
            $email = "perf-{$i}@example.test";
            $csv[] = "{$email},{$secret}";

            $rows[] = [
                'name' => "Perf User {$i}",
                'email' => $email,
                'email_verified_at' => $now,
                'password' => $password,
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt((string) json_encode(["perf-recovery-{$i}"])),
                'two_factor_confirmed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                DB::table('users')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('users')->insert($rows);
        }

        File::ensureDirectoryExists(storage_path('app/private/perf'));
        File::put(storage_path('app/private/perf/users.csv'), implode("\n", $csv)."\n");

        $this->assignRoles();
        $this->auditEvents($events, $now);
    }

    private function assignRoles(): void
    {
        $ids = User::query()->where('email', 'like', 'perf-%')->orderBy('id')->limit(50)->pluck('id')->all();
        $rows = [];

        foreach ($ids as $index => $id) {
            $rows[] = ['user_id' => $id, 'role' => $index < 10 ? Role::Administrator->value : Role::Auditor->value];
        }

        DB::table('role_assignments')->insertOrIgnore($rows);
    }

    /**
     * A valid chain, built the way AuditLogger builds it, spread over 90 days.
     */
    private function auditEvents(int $count, CarbonImmutable $now): void
    {
        $previous = DB::table('audit_events')->orderByDesc('id')->value('hash');
        $previous = is_string($previous) ? $previous : null;
        $actors = User::query()->where('email', 'like', 'perf-%')->pluck('id')->all();
        $actions = ['auth.login', 'auth.logout', 'auth.failed', 'access.role_assigned', 'auth.two_factor_confirmed'];
        $start = $now->subDays(90);
        $step = $count > 0 ? (90 * 86400) / $count : 0;
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $actor = $actors === [] ? null : $actors[$i % count($actors)];
            $fields = [
                'occurred_at' => $start->addSeconds((int) ($i * $step))->format('Y-m-d H:i:s.uP'),
                'actor_id' => $actor,
                'guard' => $actor === null ? null : 'web',
                'action' => $actions[$i % count($actions)],
                'subject_type' => User::class,
                'subject_id' => $actor === null ? null : (string) $actor,
                'request_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PerformanceSeeder',
                'changes' => null,
                'metadata' => ['seed' => $i],
            ];

            $hash = AuditChain::hash($previous, $fields);
            $rows[] = [...$fields, 'metadata' => json_encode($fields['metadata']), 'previous_hash' => $previous, 'hash' => $hash];
            $previous = $hash;

            if (count($rows) === 1000) {
                DB::table('audit_events')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('audit_events')->insert($rows);
        }
    }
}
