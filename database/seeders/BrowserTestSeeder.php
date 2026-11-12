<?php

namespace Database\Seeders;

use App\Models\User;
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

        User::factory()->create([
            'name' => 'Browser Test',
            'email' => 'browser@example.test',
            'password' => $password,
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['browser-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);

        File::ensureDirectoryExists(storage_path('app/private/browser'));
        File::put(storage_path('app/private/browser/user.json'), (string) json_encode([
            'email' => 'browser@example.test',
            'password' => $password,
            'secret' => $secret,
        ]));
    }
}
