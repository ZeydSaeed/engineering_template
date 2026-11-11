<?php

namespace App\Platform;

use App\Platform\Access\AccessCatalog;
use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Audit\RecordAuthenticationEvents;
use App\Platform\Console\VerifyAuditChainCommand;
use App\Platform\Environment\EnvironmentValidator;
use App\Platform\Observability\QueryMetrics;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(QueryMetrics::class);
    }

    public function boot(): void
    {
        $this->validateEnvironment();
        $this->registerPermissions();
        $this->watchQueries();

        Event::subscribe(RecordAuthenticationEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChainCommand::class]);
        }
    }

    /**
     * Fail closed on unsafe settings outside local and testing (ADR-0010).
     */
    private function validateEnvironment(): void
    {
        /** @var list<string> $environments */
        $environments = (array) config('platform.environment.enforce_in');
        /** @var list<string> $exempt */
        $exempt = (array) config('platform.environment.exempt_commands');

        if (! $this->app->environment($environments) || $this->app->runningConsoleCommand($exempt)) {
            return;
        }

        $this->app->make(EnvironmentValidator::class)->assertSafe();
    }

    /**
     * One Gate ability per permission, so routes say `can:roles.manage` and
     * the frontend receives plain strings (ADR-0014).
     */
    private function registerPermissions(): void
    {
        foreach (AccessCatalog::permissions() as $permission) {
            Gate::define((string) $permission->value, fn (Authenticatable $user): bool => $user instanceof HoldsRoles && $user->hasPermission($permission));
        }
    }

    /**
     * Counts database time for Server-Timing and logs slow statements and slow
     * requests (ADR-0017). Only SQL text is logged, never bound values.
     */
    private function watchQueries(): void
    {
        $slowQuery = (int) config('platform.observability.slow_query_ms');

        DB::listen(function (QueryExecuted $query) use ($slowQuery): void {
            // A statement Laravel did not time still counts; its time as zero.
            $time = $query->time ?? 0.0;
            $this->app->make(QueryMetrics::class)->record($time);

            if ($slowQuery > 0 && $time >= $slowQuery) {
                Log::warning('Slow database statement.', [
                    'ms' => round($time, 1),
                    'sql' => $query->sql,
                    'connection' => $query->connectionName,
                ]);
            }
        });

        $slowRequest = (int) config('platform.observability.slow_request_db_ms');

        if ($slowRequest > 0) {
            DB::whenQueryingForLongerThan($slowRequest, function (Connection $connection) use ($slowRequest): void {
                Log::warning('Database time of one request exceeded the limit.', [
                    'limit_ms' => $slowRequest,
                    'ms' => round($connection->totalQueryDuration(), 1),
                    'request_id' => Context::get('request_id'),
                ]);
            });
        }
    }
}
