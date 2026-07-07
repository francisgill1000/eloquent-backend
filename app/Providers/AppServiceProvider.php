<?php

namespace App\Providers;

use App\Macros\FilterByKeyMacro;
use App\Macros\SearchMacro;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Leads\Contracts\LeadSourceInterface;
use App\Services\Leads\Sources\GooglePlacesSource;
use Illuminate\Support\Facades\DB;
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

        // One navigation-action sink per request, shared by the assistant tools
        // and the owner assistant controller.
        $this->app->singleton(AssistantActions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register macros
        (new SearchMacro())();
        (new FilterByKeyMacro())();

        // Hard stop against destructive DB commands (migrate:fresh, migrate:refresh,
        // migrate:reset, db:wipe) — even with --force. Allowed ONLY where the env
        // explicitly opts in (local + staging, on disposable databases). Read via
        // config so it holds even when config is cached. See 2026-07-06 incident:
        // a cached prod config made `artisan test` (RefreshDatabase) run
        // migrate:fresh against production. Default false = blocked everywhere.
        DB::prohibitDestructiveCommands(! config('app.allow_destructive_db', false));

        if ($this->app->environment('local')) {
            Http::globalOptions(['verify' => false]);
        }
    }
}
