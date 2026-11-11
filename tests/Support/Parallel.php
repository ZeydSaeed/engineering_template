<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts tests/Concurrency/worker.php in several real PHP processes that
 * begin at the same moment, against the same PostgreSQL test database the
 * calling test uses.
 */
final class Parallel
{
    /**
     * @param  list<list<int|string>>  $argumentSets  one entry per process
     * @return list<array<string, mixed>>
     */
    public static function run(string $scenario, array $argumentSets, float $startDelay = 2.0): array
    {
        $startAt = sprintf('%.6F', microtime(true) + $startDelay);
        $processes = [];

        foreach ($argumentSets as $arguments) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Concurrency/worker.php'), $scenario, $startAt, ...array_map(strval(...), $arguments)],
                base_path(),
                self::environment(),
                null,
                180,
            );
            $process->start();
            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
            $decoded = json_decode((string) end($lines), true);

            if (! $process->isSuccessful() || ! is_array($decoded)) {
                throw new RuntimeException("Worker failed ({$process->getExitCode()}): ".$process->getErrorOutput().$process->getOutput());
            }

            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $db = (array) config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'AUDIT_HMAC_KEY' => (string) config('platform.audit.hmac_key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_STACK' => 'single',
        ];
    }
}
