<?php

namespace App\Providers;

use App\Domain\Fx\FxRatePersistenceRepository;
use App\Domain\MarketData\MarketDataPersistenceRepository;
use App\Domain\Portfolio\PortfolioImportRepository;
use App\Infrastructure\Fx\EloquentFxRatePersistenceRepository;
use App\Infrastructure\MarketData\EloquentMarketDataPersistenceRepository;
use App\Infrastructure\Portfolio\EloquentPortfolioImportRepository;
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
        $this->app->bind(PortfolioImportRepository::class, EloquentPortfolioImportRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
