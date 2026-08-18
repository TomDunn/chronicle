<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Console\Commands\ProcessCommand;
use Illuminate\Support\ServiceProvider;

class ProcessServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chronicle.php', 'chronicle');

        $this->app->singleton(Process::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/chronicle.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'chronicle');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/chronicle.php' => config_path('chronicle.php'),
        ], ['chronicle', 'chronicle-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/chronicle'),
        ], ['chronicle', 'chronicle-views']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/chronicle'),
        ], ['chronicle', 'chronicle-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['chronicle', 'chronicle-migrations']);

        $this->commands([
            ProcessCommand::class,
        ]);
    }
}
