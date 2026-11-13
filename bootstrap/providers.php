<?php

use App\Modules\Requests\RequestsServiceProvider;
use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    PlatformServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Business modules (ADR-0033).
    RequestsServiceProvider::class,
];
