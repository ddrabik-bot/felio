<?php

namespace App\Providers;

use App\Domain\Fx\FxRatePersistenceRepository;
use App\Domain\MarketData\MarketDataPersistenceRepository;
use App\Infrastructure\Fx\EloquentFxRatePersistenceRepository;
use App\Infrastructure\MarketData\EloquentMarketDataPersistenceRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FxRatePersistenceRepository::class, EloquentFxRatePersistenceRepository::class);
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
