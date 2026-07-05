<?php

namespace App\Providers;

use App\Macros\FilterByKeyMacro;
use App\Macros\SearchMacro;
use App\Services\Leads\Contracts\LeadSourceInterface;
use App\Services\Leads\Sources\GooglePlacesSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Pluggable lead sources — flip config('leads.source') to swap providers. */
    private const LEAD_SOURCES = [
        'google_places' => GooglePlacesSource::class,
        // 'explorium' => \App\Services\Leads\Sources\ExploriumSource::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Resolve the active lead-discovery source from config. Swapping to
        // Explorium later is a config flip + one class — no controller changes.
        $this->app->bind(LeadSourceInterface::class, function () {
            $key = config('leads.source', 'google_places');
            $class = self::LEAD_SOURCES[$key] ?? GooglePlacesSource::class;
            return $this->app->make($class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register macros
        (new SearchMacro())();
        (new FilterByKeyMacro())();

        if ($this->app->environment('local')) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
