<?php

namespace App\Modules\Requests;

use App\Models\User;
use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Application\Jobs\NotifyApprovers;
use App\Modules\Requests\Application\Jobs\NotifyRequester;
use App\Modules\Requests\Application\Queries\RequestsOverviewQuery;
use App\Modules\Requests\Contracts\RequestsOverview;
use App\Modules\Requests\Http\RequestPolicy;
use App\Modules\Requests\Http\RequestsTable;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Tables\TableRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The Requests module (ADR-0033): everything it adds to the application is
 * declared here, so removing the module is removing this provider and its
 * folder.
 */
class RequestsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Outbox topics this module publishes, and who handles them.
        config(['platform.outbox.handlers' => [
            ...(array) config('platform.outbox.handlers'),
            SubmitRequest::TOPIC => NotifyApprovers::class,
            DecideRequest::TOPIC => NotifyRequester::class,
        ]]);

        // The module's public read API (Contracts), for the dashboard.
        $this->app->bind(RequestsOverview::class, RequestsOverviewQuery::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');
        $this->loadRoutesFrom(__DIR__.'/Http/routes.php');

        // A stable name in audit records, files and notifications, instead
        // of the class name.
        Relation::morphMap([ApprovalRequest::MORPH => ApprovalRequest::class]);

        Gate::policy(ApprovalRequest::class, RequestPolicy::class);

        // Everyone signed in has a request list (their own at least), so its
        // columns and saved views are theirs to keep.
        Gate::define(RequestsTable::ABILITY, fn (User $user): bool => true);
        $this->app->make(TableRegistry::class)->register(RequestsTable::NAME, RequestsTable::class, RequestsTable::ABILITY);
    }
}
