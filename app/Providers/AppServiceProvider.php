<?php

namespace App\Providers;

use App\Domain\MarketData\MarketDataPersistenceRepository;
use App\Infrastructure\MarketData\EloquentMarketDataPersistenceRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MarketDataPersistenceRepository::class, EloquentMarketDataPersistenceRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
