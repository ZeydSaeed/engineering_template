<?php

namespace Database\Seeders;

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * One signed-up user with a confirmed authenticator app for the browser
 * tests (tests/browser, ADR-0024). Runs only on the browser tests' own
 * SQLite file, never on a real database. The password and authenticator
 * secret are generated per run and written for Playwright to
 * storage/app/private/browser/user.json.
 */
class BrowserTestSeeder extends Seeder
{
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

        File::ensureDirectoryExists(storage_path('app/private/browser'));
        File::put(storage_path('app/private/browser/user.json'), (string) json_encode([
            'email' => 'browser@example.test',
            'password' => $password,
            'secret' => $secret,
            // Signed up without a second factor yet: is sent to set one up.
            'setup_email' => 'setup@example.test',
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
