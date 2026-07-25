<?php

use App\Providers\AppServiceProvider;
use App\Providers\GameServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\ThemeServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    ThemeServiceProvider::class,
    GameServiceProvider::class,
];
