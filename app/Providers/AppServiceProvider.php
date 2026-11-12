<?php

namespace App\Providers;

use App\Access\Permission;
use App\Http\Tables\UsersTable;
use App\Platform\Tables\TableRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Server-side tables, for the shared column and saved-view endpoints.
        $this->app->make(TableRegistry::class)->register('users', UsersTable::class, Permission::RolesManage->value);
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

        // N+1 queries throw during development and tests; in production they
        // are logged instead of breaking the page (ADR-0017).
        if (app()->isProduction()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading (N+1) detected.', ['model' => $model::class, 'relation' => $relation]);
            });
        }
    }
}
