<?php

namespace Kompo\Searchbar;

use Kompo\Searchbar\SearchService;
use Kompo\Searchbar\SearchItems\Stores\SearchStore;
use Illuminate\Support\ServiceProvider;

class SearchbarServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SearchStore::class, function ($_, $params) {
            return (new (config('searchbar.store'))($params['key']))->injectContext($params['contextService']);
        });

        // Enhanced in Helpers\searchbar.php 
        $this->app->singleton('search-service', function() {
            return new SearchService();
        });

        $this->app->bind('search-state-model', function() {
            return new (config('searchbar.searchstate_model'));
        });

        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__ . '/../routes/web.php');
        });
    }

    public function boot(): void
    {
        $this->loadConfig();
        
        $this->loadPublishing();

        $this->loadHelpers();

        $this->loadJSONTranslationsFrom(__DIR__.'/../resources/lang');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadCommands();
    }

    protected function loadCommands()
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \Kompo\Searchbar\Commands\PruneSearchLinksCommand::class,
            // Run by hand on deploy (--dry-run first): stored states to format v2, or back with --rollback.
            \Kompo\Searchbar\Commands\MigrateSearchStatesCommand::class,
        ]);

        // Only resolved by the scheduler (schedule:run / schedule:list).
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->command('searchbar:prune-links')->daily();
        });
    }

    protected function loadHelpers()
    {
        $helpersDir = __DIR__.'/Helpers';

        $autoloadedHelpers = collect(\File::allFiles($helpersDir))->map(fn($file) => $file->getRealPath());

        $packageHelpers = [
        ];

        $autoloadedHelpers->concat($packageHelpers)->each(function ($path) {
            if (file_exists($path)) {
                require_once $path;
            }
        });
    }

    protected function loadConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/searchbar.php', 'searchbar');
    }

    protected function loadPublishing()
    {
        $this->publishes([
            __DIR__.'/../config/searchbar.php' => config_path('searchbar.php'),
        ], 'searchbar-config');
    }
}
