<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes whatever it connects to, so tests refuse to start
     * on anything but in-memory SQLite or the dedicated PostgreSQL test
     * database (platform.database.testing_name).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");
        $allowed = ['sqlite::memory:', 'pgsql:'.$app['config']->get('platform.database.testing_name')];

        if (! in_array("{$connection}:{$database}", $allowed, true)) {
            throw new RuntimeException("Refusing to run tests against [{$connection}:{$database}].");
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
