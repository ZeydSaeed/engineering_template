<?php

namespace App\Modules\Requests;

use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Application\Jobs\NotifyApprovers;
use App\Modules\Requests\Application\Jobs\NotifyRequester;
use App\Modules\Requests\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        // A stable name in audit records, files and notifications, instead
        // of the class name.
        Relation::morphMap([ApprovalRequest::MORPH => ApprovalRequest::class]);
    }
}
