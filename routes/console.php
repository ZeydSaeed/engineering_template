<?php

use Illuminate\Support\Facades\Schedule;

// A broken chain means the audit trail was altered (ADR-0013).
Schedule::command('platform:audit-verify')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
