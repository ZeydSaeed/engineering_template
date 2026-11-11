<?php

namespace App\Platform;

use App\Platform\Access\AccessCatalog;
use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Audit\RecordAuthenticationEvents;
use App\Platform\Console\BackupCommand;
use App\Platform\Console\HealthCommand;
use App\Platform\Console\RelayOutboxCommand;
use App\Platform\Console\RestoreDrillCommand;
use App\Platform\Console\SweepFilesCommand;
use App\Platform\Console\VerifyAuditChainCommand;
use App\Platform\Environment\EnvironmentValidator;
use App\Platform\Files\ClamAvScanner;
use App\Platform\Files\Contracts\FileScanner;
use App\Platform\Files\StructuralScanner;
use App\Platform\Observability\QueryMetrics;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(QueryMetrics::class);

        // The configured scanner; ClamAV always runs the structural checks
        // first (ADR-0020).
        $this->app->bind(FileScanner::class, function (): FileScanner {
            $structural = new StructuralScanner((int) config('platform.files.max_image_pixels'));

            return match (config('platform.files.scanner')) {
                'structural' => $structural,
                'clamav' => new ClamAvScanner(
                    $structural,
                    (string) config('platform.files.clamav.host'),
                    (int) config('platform.files.clamav.port'),
                    (int) config('platform.files.clamav.timeout'),
                ),
                default => throw new InvalidArgumentException('Unknown platform.files.scanner ['.config('platform.files.scanner').'].'),
            };
        });
    }

    public function boot(): void
    {
        $this->validateEnvironment();
        $this->registerPermissions();
        $this->watchQueries();
        $this->watchQueues();

        Event::subscribe(RecordAuthenticationEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyAuditChainCommand::class, RelayOutboxCommand::class, SweepFilesCommand::class,
                BackupCommand::class, RestoreDrillCommand::class, HealthCommand::class,
            ]);
        }
    }

    /**
     * Permanent job failures and queue backlogs reach the log (ADR-0019).
     * Never the job's payload or the exception message, which may carry data.
     */
    private function watchQueues(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::error('Job failed after its last attempt.', [
                'job' => $event->job->resolveName(),
                'uuid' => $event->job->uuid(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => $event->exception::class,
            ]);
        });

        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::warning('Queue backlog above its limit.', [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
        });
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

        // HTTP only: the duration is counted per connection, and a console
        // command or queue worker keeps one connection for many units of work.
        if ($slowRequest > 0 && ! $this->app->runningInConsole()) {
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
