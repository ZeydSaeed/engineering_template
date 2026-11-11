<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\RedisProbeJob;

/*
| A real round trip through Redis. Runs where REDIS_QUEUE_TEST=1 (CI starts a
| Redis service); skipped elsewhere, since local development uses the
| database queue (ADR-0019).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (getenv('REDIS_QUEUE_TEST') !== '1') {
        $this->markTestSkipped('Set REDIS_QUEUE_TEST=1 with a reachable Redis.');
    }

    config(['queue.default' => 'redis']);
    Redis::connection()->flushdb();
});

it('queues a job on Redis and runs it on a worker', function () {
    $marker = (string) Str::uuid();

    dispatch(new RedisProbeJob($marker));

    expect(Redis::connection()->llen('queues:default'))->toBe(1);

    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'default', '--stop-when-empty' => true]);

    expect(DB::table('cache')->where('key', "redis-probe:{$marker}")->value('value'))->toBe('ran')
        ->and(Redis::connection()->llen('queues:default'))->toBe(0);
});
