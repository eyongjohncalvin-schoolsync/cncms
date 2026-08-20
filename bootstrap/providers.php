<?php

use App\Providers\AppServiceProvider;
use App\Providers\RepositoryServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    RepositoryServiceProvider::class,
];
