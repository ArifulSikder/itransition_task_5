<?php

namespace App\Providers;

use App\Movie\LocaleCatalog;
use App\Movie\TrailerComposer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocaleCatalog::class, function () {
            return new LocaleCatalog(resource_path('locales'));
        });

        $this->app->singleton(TrailerComposer::class, function () {
            return new TrailerComposer(resource_path('trailers/clips.json'));
        });
    }

    public function boot(): void
    {
        //
    }
}
