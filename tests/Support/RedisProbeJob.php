<?php

namespace Tests\Support;

use App\Platform\Jobs\PlatformJob;
use Illuminate\Support\Facades\DB;

/**
 * Test job: leaves a marker row, so a test can tell it ran on a worker.
 */
class RedisProbeJob extends PlatformJob
{
    public function __construct(public readonly string $marker)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        DB::table('cache')->insert(['key' => 'redis-probe:'.$this->marker, 'value' => 'ran', 'expiration' => time() + 60]);
    }
}
