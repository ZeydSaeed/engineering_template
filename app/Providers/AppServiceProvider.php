<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // The same rules everywhere, so tests see what production enforces.
        // Only the breach check, which calls an outside service, is limited
        // to production (ADR-0015).
        Password::defaults(function (): Password {
            $rule = Password::min(12)->max(128)->mixedCase()->letters()->numbers()->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });

        Model::shouldBeStrict(! app()->isProduction());
    }
}
