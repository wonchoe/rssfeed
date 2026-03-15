<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\HorizonServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    HorizonServiceProvider::class,
    SocialiteServiceProvider::class,
];
