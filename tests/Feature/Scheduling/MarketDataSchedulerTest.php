<?php

use App\Application\Scheduling\DailyPortfolioSnapshotService;
use App\Application\Scheduling\MarketDataRefreshService;
use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRateProvider;
use App\Domain\Fx\FxRateResult;
use App\Domain\MarketData\DailyOhlc;
use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataError;
use App\Domain\MarketData\MarketDataErrorCategory;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\MarketData\MarketDataSnapshot;
use App\Domain\MarketData\ProviderInstrumentMapping;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

function scheduledPosition(string $instrument, string $quantity = '2', string $asOf = '2026-08-10T00:00:00+00:00'): void
{
    $user = User::factory()->create();
    DB::table('portfolio_accounts')->insert([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => 'scheduler-'.$instrument,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(PortfolioImportService::class)->persist(new ImportBatch('xtb', 'scheduler-'.$instrument, 'batch-'.$instrument, new DateTimeImmutable($asOf)), [
        PortfolioImportRow::valid('row-'.$instrument, new \App\Domain\MarketData\CanonicalInstrument($instrument), $quantity, null, new DateTimeImmutable($asOf), ['instrument' => $instrument, 'quantity' => $quantity]),
    ]);
}

function schedulerMarketData(ProviderInstrumentMapping $mapping, string $date = '2026-08-12'): InstrumentMarketData
{
    return InstrumentMarketData::available($mapping, new MarketDataSnapshot(
        $mapping->exchange,
        $mapping->quoteCurrency,
        [new DailyOhlc($date, '10.123456789012345678', '11', '9', '10.123456789012345678')],
        [], [], '2026-08-12T10:00:00+00:00', 'UTC', 'test-provider-v1',
    ));
}

function schedulerFx(string $currency, string $date = '2026-08-12'): FxRateResult
{
    return new FxRateResult($currency, new DateTimeImmutable($date.'T00:00:00+02:00'), new DateTimeImmutable($date.'T00:00:00+02:00'), FxRateAvailability::Available, '4.123456789012345678', null, 1, new DateTimeImmutable($date.'T10:00:00+00:00'), 'https://example.test/fx', 'A', '1/A/NBP/2026', 'Europe/Warsaw', 'test-nbp-v1', 'test');
}

it('refreshes only the active confirmed instrument universe and persists exact current prices and required FX', function (): void {
    scheduledPosition('OTLK.US');
    $inactive = DB::table('portfolio_accounts')->insertGetId(['broker' => 'xtb', 'account_reference' => 'inactive', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('portfolio_import_batches')->insert(['portfolio_account_id' => $inactive, 'source_batch_identity' => 'inactive-batch', 'imported_at' => now(), 'status' => 'COMPLETED', 'created_at' => now(), 'updated_at' => now()]);

    $calls = [];
    $provider = new class($calls) implements MarketDataProvider {
        public array $calls = [];
        public function __construct(array $calls) {}
        public function availability(ProviderInstrumentMapping $mapping): \App\Domain\MarketData\InstrumentAvailability { throw new LogicException('unused'); }
        public function fetch(ProviderInstrumentMapping $mapping): InstrumentMarketData { $this->calls[] = $mapping->instrument->value; return schedulerMarketData($mapping); }
    };
    $fxCalls = [];
    $fx = new class($fxCalls) implements FxRateProvider {
        public array $calls = [];
        public function __construct(array $calls) {}
        public function historical(string $currency, DateTimeImmutable $requestedDate): FxRateResult { throw new LogicException('unused'); }
        public function current(string $currency, DateTimeImmutable $asOfDate): FxRateResult { $this->calls[] = $currency; return schedulerFx($currency, $asOfDate->format('Y-m-d')); }
        public function historicalMany(array $currencies, DateTimeImmutable $requestedDate): array { return []; }
    };
    app()->instance(MarketDataProvider::class, $provider);
    app()->instance(FxRateProvider::class, $fx);

    app(MarketDataRefreshService::class)->refresh(new DateTimeImmutable('2026-08-12T12:00:00+02:00'));

    expect($provider->calls)->toBe(['OTLK.US'])
        ->and($fx->calls)->toBe(['USD'])
        ->and(DB::table('daily_ohlc_observations')->value('close'))->toBe('10.123456789012345678')
        ->and(DB::table('fx_rate_snapshots')->value('pln_per_unit'))->toBe('4.123456789012345678')
        ->and(DB::table('market_data_refresh_outcomes')->count())->toBe(2)
        ->and(DB::table('portfolio_value_snapshots')->where('valuation_date', '2026-08-12')->value('availability'))->toBe('available')
        ->and(DB::table('portfolio_value_snapshots')->where('valuation_date', '2026-08-12')->value('total_pln_grosze'))->toBe(8349);
});

it('does not persist partial price or FX data when either provider throws', function (): void {
    scheduledPosition('OTLK.US');
    scheduledPosition('PZU.PL');

    app()->instance(MarketDataProvider::class, new class implements MarketDataProvider {
        private int $calls = 0;

        public function availability(ProviderInstrumentMapping $mapping): \App\Domain\MarketData\InstrumentAvailability { throw new LogicException('unused'); }

        public function fetch(ProviderInstrumentMapping $mapping): InstrumentMarketData
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('provider timeout');
            }

            return schedulerMarketData($mapping);
        }
    });
    app()->instance(FxRateProvider::class, new class implements FxRateProvider {
        public function historical(string $currency, DateTimeImmutable $requestedDate): FxRateResult { throw new LogicException('unused'); }
        public function current(string $currency, DateTimeImmutable $asOfDate): FxRateResult { throw new LogicException('unused'); }
        public function historicalMany(array $currencies, DateTimeImmutable $requestedDate): array { return []; }
    });

    app(MarketDataRefreshService::class)->refresh(new DateTimeImmutable('2026-08-12T12:00:00+02:00'));

    expect(DB::table('market_data_snapshots')->count())->toBe(0)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(0)
        ->and(DB::table('fx_rate_snapshots')->count())->toBe(0)
        ->and(DB::table('market_data_refresh_outcomes')->where('subject_type', 'instrument')->where('availability', 'unavailable')->value('reason'))->toBe('provider_exception');
});

it('records no-data and unavailable reasons without writing source observations', function (): void {
    scheduledPosition('OTLK.US');
    $provider = new class implements MarketDataProvider {
        public function availability(ProviderInstrumentMapping $mapping): \App\Domain\MarketData\InstrumentAvailability { throw new LogicException('unused'); }
        public function fetch(ProviderInstrumentMapping $mapping): InstrumentMarketData { return InstrumentMarketData::noData($mapping, MarketDataError::from(MarketDataErrorCategory::InstrumentNotFound, 'provider_empty_history')); }
    };
    app()->instance(MarketDataProvider::class, $provider);
    app()->instance(FxRateProvider::class, new class implements FxRateProvider {
        public function historical(string $currency, DateTimeImmutable $requestedDate): FxRateResult { throw new LogicException('unused'); }
        public function current(string $currency, DateTimeImmutable $asOfDate): FxRateResult { throw new LogicException('unused'); }
        public function historicalMany(array $currencies, DateTimeImmutable $requestedDate): array { return []; }
    });

    app(MarketDataRefreshService::class)->refresh(new DateTimeImmutable('2026-08-12T12:00:00+02:00'));

    expect(DB::table('market_data_snapshots')->count())->toBe(0)
        ->and(DB::table('fx_rate_snapshots')->count())->toBe(0)
        ->and(DB::table('market_data_refresh_outcomes')->first()->reason)->toBe('provider_empty_history')
        ->and(DB::table('market_data_refresh_outcomes')->first()->availability)->toBe('no_data');
});

it('writes daily cache snapshots idempotently and forward fills a missing day from confirmed source positions', function (): void {
    scheduledPosition('PZU.PL', '3', '2026-08-10T00:00:00+00:00');
    $accountId = (int) DB::table('portfolio_accounts')->where('account_reference', 'scheduler-PZU.PL')->value('id');
    $snapshotId = DB::table('market_data_snapshots')->insertGetId(['canonical_instrument' => 'PZU.PL', 'provider' => 'test', 'provider_symbol' => 'PZU.WA', 'exchange' => 'WSE', 'quote_currency' => 'PLN', 'session_date' => '2026-08-10', 'retrieved_at' => now(), 'source_timezone' => 'UTC', 'provider_version' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('daily_ohlc_observations')->insert(['market_data_snapshot_id' => $snapshotId, 'trading_date' => '2026-08-10', 'open' => '10', 'high' => '10', 'low' => '10', 'close' => '10', 'created_at' => now(), 'updated_at' => now()]);

    $service = app(DailyPortfolioSnapshotService::class);
    $service->snapshot(new DateTimeImmutable('2026-08-12T00:30:00+02:00'));
    $service->snapshot(new DateTimeImmutable('2026-08-12T00:30:00+02:00'));

    expect(DB::table('portfolio_value_snapshots')->where('portfolio_account_id', $accountId)->count())->toBe(3)
        ->and(DB::table('portfolio_value_snapshots')->where('portfolio_account_id', $accountId)->where('valuation_date', '2026-08-12')->value('availability'))->toBe('available')
        ->and(DB::table('portfolio_value_snapshots')->where('portfolio_account_id', $accountId)->where('valuation_date', '2026-08-12')->value('total_pln_grosze'))->toBe(3000);
});

it('registers non-overlapping five-minute refresh and daily snapshot scheduler events', function (): void {
    $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

    expect(collect($events)->contains(fn ($event): bool => $event->expression === '*/5 * * * *' && $event->withoutOverlapping))->toBeTrue()
        ->and(collect($events)->contains(fn ($event): bool => $event->expression === '30 0 * * *' && $event->withoutOverlapping))->toBeTrue();
});
