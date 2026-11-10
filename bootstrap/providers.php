<?php

use App\Platform\PlatformServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    PlatformServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
