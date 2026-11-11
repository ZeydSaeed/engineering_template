<?php

use App\Platform\Jobs\QueueSettings;
use Illuminate\Support\Facades\Schedule;

// A broken chain means the audit trail was altered (ADR-0013).
Schedule::command('platform:audit-verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Fallback relay; production also runs `platform:outbox-relay --loop` as a
// service for sub-second delivery (ADR-0019).
Schedule::command('platform:outbox-relay')->everyMinute()->withoutOverlapping()->onOneServer();

// Backlog warnings per queue (QueueBusy, logged by the platform).
Schedule::command('queue:monitor', [
    implode(',', array_map(fn (QueueSettings $queue): string => $queue->connection().':'.$queue->name, QueueSettings::all())),
    '--max' => 100,
])->everyMinute()->onOneServer();

Schedule::command('queue:prune-failed', ['--hours' => 720])->daily()->onOneServer();
