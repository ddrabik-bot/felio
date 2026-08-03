<?php

namespace App\Providers;

use App\Application\Portfolio\PortfolioValuationService;
use App\Domain\Fx\FxRatePersistenceRepository;
use App\Domain\MarketData\MarketDataPersistenceRepository;
use App\Domain\Portfolio\PortfolioImportRepository;
use App\Domain\Portfolio\PortfolioValuationReadRepository;
use App\Domain\Valuation\StaleFxRatePolicy;
use App\Domain\Valuation\ValuationService;
use App\Infrastructure\Fx\EloquentFxRatePersistenceRepository;
use App\Infrastructure\MarketData\EloquentMarketDataPersistenceRepository;
use App\Infrastructure\Portfolio\EloquentPortfolioImportRepository;
use App\Infrastructure\Portfolio\EloquentPortfolioValuationReadRepository;
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
        $this->app->bind(PortfolioValuationReadRepository::class, EloquentPortfolioValuationReadRepository::class);
        $this->app->bind(PortfolioValuationService::class, static fn ($app): PortfolioValuationService => new PortfolioValuationService(
            $app->make(PortfolioValuationReadRepository::class),
            new ValuationService(StaleFxRatePolicy::Reject),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
