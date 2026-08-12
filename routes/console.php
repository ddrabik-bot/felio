<?php

use App\Application\Scheduling\DailyPortfolioSnapshotService;
use App\Application\Scheduling\MarketDataRefreshService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static fn () => app(MarketDataRefreshService::class)->refresh(new DateTimeImmutable('now')))
    ->name('market-data-refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::call(static fn () => app(DailyPortfolioSnapshotService::class)->snapshot(new DateTimeImmutable('now')))
    ->name('daily-portfolio-snapshot')
    ->dailyAt('00:30')
    ->withoutOverlapping();
