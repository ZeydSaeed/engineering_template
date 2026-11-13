<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Notifications\RoleChanged;
use App\Platform\Access\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * One signed-up user with a confirmed authenticator app for the browser
 * tests (tests/browser, ADR-0024), and an approver for the Requests module
 * (ADR-0033). Runs only on the browser tests' own
 * SQLite file, never on a real database. The password and authenticator
 * secret are generated per run and written for Playwright to
 * storage/app/private/browser/user.json.
 */
class BrowserTestSeeder extends Seeder
{
    /** A request waiting for a decision, at a fixed address. */
    public const WAITING_REQUEST = '01960000-0000-7000-8000-000000000001';

    public function run(Google2FA $google2fa): void
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        if (app()->environment('production') || ($connection['driver'] ?? '') !== 'sqlite' || ! str_ends_with((string) ($connection['database'] ?? ''), 'browser.sqlite')) {
            throw new RuntimeException('BrowserTestSeeder runs only on database/browser.sqlite.');
        }

        $password = bin2hex(random_bytes(12)).'-Aa1';
        $secret = $google2fa->generateSecretKey(32);

        $browser = User::factory()->create([
            'name' => 'Browser Test',
            'email' => 'browser@example.test',
            'password' => $password,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['browser-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        RoleAssignment::query()->forceCreate(['user_id' => $browser->id, 'role' => Role::Administrator->value]);

        // Two unread notifications, for the notification tests.
        $browser->notify(new RoleChanged('role_granted', Role::Administrator->value, 'Setup'));
        $browser->notify(new RoleChanged('role_granted', Role::Auditor->value, 'Setup'));

        // Enough people for two pages of the user list, with mixed roles.
        foreach (range(1, 40) as $i) {
            $user = User::factory()->create([
                'name' => sprintf('Person %02d', $i),
                'email' => sprintf('person-%02d@example.test', $i),
                'two_factor_confirmed_at' => $i % 4 === 0 ? null : now(),
            ]);

            if ($i % 5 === 0) {
                RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Auditor->value]);
            }
        }

        // An approver who signs in during the request tests, and a request
        // waiting for a decision at a fixed address (pages.spec.ts).
        $approverSecret = $google2fa->generateSecretKey(32);
        $approver = User::factory()->create([
            'name' => 'Approver Test',
            'email' => 'approver@example.test',
            'password' => $password,
            'two_factor_secret' => encrypt($approverSecret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['approver-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
        RoleAssignment::query()->forceCreate(['user_id' => $approver->id, 'role' => Role::Approver->value]);

        $waiting = new ApprovalRequest;
        $waiting->forceFill([
            'uuid' => self::WAITING_REQUEST,
            'requester_id' => User::query()->where('email', 'person-02@example.test')->value('id'),
            'title' => 'Ergonomic chair',
            'details' => "For the reception desk.\nThe current one is broken.",
            'amount' => '349.90',
            'status' => RequestStatus::Submitted,
            'submitted_at' => now()->subDay(),
        ])->save();

        File::ensureDirectoryExists(storage_path('app/private/browser'));
        File::put(storage_path('app/private/browser/user.json'), (string) json_encode([
            'email' => 'browser@example.test',
            'password' => $password,
            'secret' => $secret,
            // Signed up without a second factor yet: is sent to set one up.
            'setup_email' => 'setup@example.test',
            'approver_email' => 'approver@example.test',
            'approver_secret' => $approverSecret,
            'waiting_request' => self::WAITING_REQUEST,
        ]));

        User::factory()->create([
            'name' => 'Setup Test',
            'email' => 'setup@example.test',
            'password' => $password,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
