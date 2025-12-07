<?php

namespace App\Providers;

use App\Macros\FilterByKeyMacro;
use App\Macros\SearchMacro;
use Illuminate\Support\ServiceProvider;

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
        // Register macros
        (new SearchMacro())();
        (new FilterByKeyMacro())();
    }
}
