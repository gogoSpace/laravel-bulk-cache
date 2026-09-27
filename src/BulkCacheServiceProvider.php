<?php

namespace GogoSpace\BulkCache;

use GogoSpace\BulkCache\Console\DiagnoseCommand;
use GogoSpace\BulkCache\Exceptions\ConfigurationException;
use GogoSpace\BulkCache\Http\RefreshMiddleware;
use GogoSpace\BulkCache\Support\Clock;
use GogoSpace\BulkCache\Support\LoadContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

final class BulkCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bulk-cache.php', 'bulk-cache');
        $this->app->singleton(BulkCacheManager::class);
        $this->app->singleton(Clock::class);
        $this->app->scoped(LoadContext::class);
        $this->app->scoped(RefreshScheduler::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DiagnoseCommand::class]);
        }
        $this->publishes([__DIR__.'/../config/bulk-cache.php' => config_path('bulk-cache.php')], 'bulk-cache-config');
        $this->app->afterResolving(Kernel::class, function ($kernel): void {
            if (! $kernel instanceof \Illuminate\Foundation\Http\Kernel) {
                throw new ConfigurationException('The bulk cache HTTP middleware requires a Laravel HTTP kernel.');
            }
            $kernel->pushMiddleware(RefreshMiddleware::class);
        });
        if ($this->app->resolved(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);
            if ($kernel instanceof \Illuminate\Foundation\Http\Kernel) {
                $kernel->pushMiddleware(RefreshMiddleware::class);
            }
        }
    }
}
