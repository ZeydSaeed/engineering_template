<?php

namespace App\Platform\Observability;

use App\Platform\Backup\BackupSettings;
use App\Platform\Console\VerifyAuditChainCommand;
use App\Platform\Files\StoredFile;
use App\Platform\Jobs\QueueSettings;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Application metrics in the Prometheus text format (ADR-0023): queues,
 * outbox, files, backups and the audit chain. Host, PostgreSQL, Redis and
 * PHP-FPM metrics come from their own exporters. Every query is a count over
 * an indexed column, cheap enough for a scrape every 30 seconds.
 */
class PlatformMetrics
{
    /** @var list<string> */
    private array $lines = [];

    public function render(): string
    {
        $this->lines = [];
        $now = now()->getTimestamp();

        $this->gauge('platform_up', 'Application answered the scrape.', 1);
        $this->queues($now);
        $this->gauge('platform_failed_jobs', 'Jobs that failed after their last attempt and are kept for review.', DB::table('failed_jobs')->count());
        $this->outbox($now);
        $this->files($now);
        $this->backups();
        $this->audit();

        return implode("\n", $this->lines)."\n";
    }

    private function queues(int $now): void
    {
        $this->type('platform_queue_jobs', 'Jobs waiting or running, per queue.');
        $this->type('platform_queue_oldest_job_age_seconds', 'Age of the oldest waiting job, per queue (0 when none).');

        foreach (QueueSettings::all() as $queue) {
            $connection = $queue->connection();
            $size = Queue::connection($connection)->size($queue->name);
            $this->sample('platform_queue_jobs', ['queue' => $queue->name], $size);

            // Always exported: an alert on a missing series never fires.
            $oldest = $this->oldestJobCreatedAt($connection, $queue->name);
            $this->sample('platform_queue_oldest_job_age_seconds', ['queue' => $queue->name], $oldest === null ? 0 : max(0, $now - $oldest));
        }
    }

    /**
     * Unix time the oldest waiting job was queued, or null when none waits.
     */
    private function oldestJobCreatedAt(string $connection, string $queue): ?int
    {
        $settings = (array) config("queue.connections.{$connection}");

        return match ($settings['driver'] ?? null) {
            'database' => ($value = DB::table((string) ($settings['table'] ?? 'jobs'))
                ->where('queue', $queue)->whereNull('reserved_at')->min('available_at')) === null ? null : (int) $value,
            // Jobs are pushed to the tail and taken from the head: index 0 is
            // the oldest. Its payload carries Laravel's createdAt.
            'redis' => (function () use ($settings, $queue): ?int {
                $payload = Redis::connection((string) ($settings['connection'] ?? 'default'))->lindex("queues:{$queue}", 0);
                $decoded = is_string($payload) ? json_decode($payload, true) : null;

                return is_array($decoded) && isset($decoded['createdAt']) ? (int) $decoded['createdAt'] : null;
            })(),
            // sync and others hold no waiting jobs.
            default => null,
        };
    }

    private function outbox(int $now): void
    {
        $this->type('platform_outbox_messages', 'Outbox messages per status.');
        $counts = OutboxMessage::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        foreach (['pending', 'dispatched', 'dead'] as $status) {
            $this->sample('platform_outbox_messages', ['status' => $status], (int) ($counts[$status] ?? 0));
        }

        $oldest = OutboxMessage::query()->where('status', 'pending')->where('available_at', '<=', now())->min('available_at');
        $this->gauge('platform_outbox_oldest_pending_age_seconds', 'Age of the oldest due outbox message.', $oldest === null ? 0 : max(0, $now - (int) strtotime((string) $oldest)));
    }

    private function files(int $now): void
    {
        $this->type('platform_files', 'Stored files per status.');
        $counts = StoredFile::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        foreach ([StoredFile::PENDING, StoredFile::VERIFIED, StoredFile::REJECTED] as $status) {
            $this->sample('platform_files', ['status' => $status], (int) ($counts[$status] ?? 0));
        }

        $oldest = StoredFile::query()->where('status', StoredFile::PENDING)->min('created_at');
        $this->gauge('platform_files_oldest_pending_age_seconds', 'Age of the oldest file waiting for its scan.', $oldest === null ? 0 : max(0, $now - (int) strtotime((string) $oldest)));
    }

    private function backups(): void
    {
        $file = BackupSettings::directory().'/status.json';
        $status = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];

        $this->type('platform_backup_last_success_timestamp_seconds', 'Unix time of the last successful backup, per kind (0: never).');
        $this->type('platform_backup_last_run_ok', 'Whether the last backup run of the kind succeeded.');

        foreach (['daily', 'manual'] as $kind) {
            $entry = (array) ($status[$kind] ?? []);
            $success = isset($entry['last_success_at']) ? (int) strtotime((string) $entry['last_success_at']) : 0;
            $this->sample('platform_backup_last_success_timestamp_seconds', ['kind' => $kind], $success);
            $this->sample('platform_backup_last_run_ok', ['kind' => $kind], ($entry['ok'] ?? true) ? 1 : 0);
        }
    }

    private function audit(): void
    {
        $last = Cache::get(VerifyAuditChainCommand::STATUS_KEY);
        $last = is_array($last) ? $last : [];

        $this->gauge('platform_audit_chain_ok', 'Whether the last verification found the audit chain intact (1 until first run).', ($last['ok'] ?? true) ? 1 : 0);
        $this->gauge('platform_audit_chain_last_verified_timestamp_seconds', 'Unix time of the last audit chain verification (0: never).', (int) ($last['at'] ?? 0));
    }

    private function gauge(string $name, string $help, int|float $value): void
    {
        $this->type($name, $help);
        $this->sample($name, [], $value);
    }

    private function type(string $name, string $help): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} gauge";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels, int|float $value): void
    {
        $rendered = $labels === [] ? '' : '{'.implode(',', array_map(
            fn (string $key, string $label): string => $key.'="'.addcslashes($label, "\\\"\n").'"',
            array_keys($labels),
            $labels,
        )).'}';

        $this->lines[] = "{$name}{$rendered} {$value}";
    }
}
