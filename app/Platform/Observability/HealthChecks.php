<?php

namespace App\Platform\Observability;

use App\Platform\Jobs\QueueSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the application needs to serve requests (ADR-0023). The public
 * readiness probe reports pass or fail only; details stay in the logs and
 * in `php artisan platform:health`.
 */
class HealthChecks
{
    /**
     * @return array<string, bool>
     */
    public function run(): array
    {
        return [
            'database' => $this->attempt(fn (): bool => (int) DB::scalar('select 1') === 1),
            'cache' => $this->attempt(function (): bool {
                $key = 'health:'.Str::random(12);
                Cache::put($key, 'ok', 10);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $ok;
            }),
            'files' => $this->attempt(function (): bool {
                $disk = Storage::disk((string) config('platform.files.disk'));
                $path = '.health/'.Str::random(16);
                $disk->put($path, 'ok');
                $ok = $disk->get($path) === 'ok';
                $disk->delete($path);

                return $ok;
            }),
            'queue' => $this->attempt(fn (): bool => Queue::connection(QueueSettings::for('default')->connection())->size('default') >= 0),
        ];
    }

    public function healthy(): bool
    {
        return ! in_array(false, $this->run(), true);
    }

    private function attempt(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
