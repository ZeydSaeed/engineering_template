<?php

/*
| Writes N audit records through AuditLogger, one transaction each, and prints
| the elapsed time as JSON. Started several times in parallel by
| scripts/bench/audit-writes.ps1 to measure the cost of serialising writers
| (ADR-0013). Local development database only.
|
|   php scripts/bench/audit-writes.php 500 worker-1
*/

use App\Platform\Audit\AuditLogger;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (! $app->environment('local') || $database !== 'engineering_template') {
    fwrite(STDERR, "Refusing: local environment and engineering_template only (got {$database}).\n");
    exit(1);
}

$count = max(1, (int) ($argv[1] ?? 100));
$worker = (string) ($argv[2] ?? 'worker');
$logger = $app->make(AuditLogger::class);
$latencies = [];

$start = hrtime(true);

for ($i = 0; $i < $count; $i++) {
    $t = hrtime(true);
    $logger->record('bench.audit_write', metadata: ['worker' => $worker, 'n' => $i]);
    $latencies[] = (hrtime(true) - $t) / 1e6;
}

$elapsed = (hrtime(true) - $start) / 1e6;
sort($latencies);

echo json_encode([
    'worker' => $worker,
    'writes' => $count,
    'elapsed_ms' => round($elapsed, 1),
    'p50_ms' => round($latencies[(int) floor(0.50 * ($count - 1))], 2),
    'p95_ms' => round($latencies[(int) floor(0.95 * ($count - 1))], 2),
    'max_ms' => round(end($latencies), 2),
]), PHP_EOL;
