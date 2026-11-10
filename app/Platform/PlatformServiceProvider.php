<?php

namespace App\Platform;

use App\Platform\Environment\EnvironmentValidator;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->validateEnvironment();
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
}
