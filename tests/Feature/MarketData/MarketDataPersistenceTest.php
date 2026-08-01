<?php

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\MarketData\DailyOhlc;
use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataError;
use App\Domain\MarketData\MarketDataErrorCategory;
use App\Domain\MarketData\MarketDataPersistenceService;
use App\Domain\MarketData\MarketDataProviderName;
use App\Domain\MarketData\MarketDataSnapshot;
use App\Domain\MarketData\ProviderInstrumentMapping;
use App\Domain\MarketData\ProviderSymbol;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

function persistedMarketData(): InstrumentMarketData
{
    $mapping = new ProviderInstrumentMapping(
        instrument: new CanonicalInstrument('OTLK.US'),
        provider: MarketDataProviderName::YahooFinance,
        symbol: new ProviderSymbol('OTLK'),
        exchange: 'NCM',
        quoteCurrency: 'USD',
    );

    return InstrumentMarketData::available($mapping, new MarketDataSnapshot(
        exchange: 'NCM',
        quoteCurrency: 'USD',
        dailyOhlc: [new DailyOhlc('2026-07-31', '1.10000000000000000001', '1.20', '1.00', '1.15')],
        dividends: [
            ['date' => '2026-01-15', 'amount' => '0.25000000000000000001', 'provider_event_id' => 'div-101'],
            ['date' => '2026-01-15', 'amount' => '0.25000000000000000001', 'provider_event_id' => 'div-102'],
            ['date' => '2026-01-15', 'amount' => '0.30'],
        ],
        splits: [['date' => '2026-01-15', 'ratio' => '1.5']],
        retrievedAt: '2026-08-01T21:15:00+00:00',
        sourceTimezone: 'America/New_York',
        providerVersion: 'chart-v8',
    ));
}

it('persists an available EOD snapshot with exact decimal strings and distinct same-day raw corporate actions', function (): void {
    app(MarketDataPersistenceService::class)->persist(persistedMarketData(), '2026-08-01');

    expect(DB::table('market_data_snapshots')->count())->toBe(1)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(1)
        ->and(DB::table('corporate_actions')->count())->toBe(4)
        ->and(DB::table('daily_ohlc_observations')->value('open'))->toBe('1.10000000000000000001')
        ->and(DB::table('corporate_actions')->where('action_type', 'dividend')->orderBy('id')->pluck('value')->all())
        ->toBe(['0.25000000000000000001', '0.25000000000000000001', '0.30'])
        ->and(DB::table('corporate_actions')->where('action_type', 'split')->value('value'))->toBe('1.5')
        ->and(DB::table('market_data_snapshots')->first(['retrieved_at', 'source_timezone', 'provider_version']))
        ->toMatchObject([
            'retrieved_at' => '2026-08-01 21:15:00+00',
            'source_timezone' => 'America/New_York',
            'provider_version' => 'chart-v8',
        ]);
});

it('is retry-safe with native upserts and retains every distinct raw corporate action identity', function (): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service = app(MarketDataPersistenceService::class);
    $service->persist(persistedMarketData(), '2026-08-01');
    $service->persist(persistedMarketData(), '2026-08-01');

    expect(DB::table('market_data_snapshots')->count())->toBe(1)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(1)
        ->and(DB::table('corporate_actions')->count())->toBe(4)
        ->and(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'on conflict')))->toBeTrue();
});

it('enforces the unique PostgreSQL conflict targets required for concurrent upserts', function (): void {
    $indexes = collect(DB::select("select indexname from pg_indexes where schemaname = 'public'"))
        ->pluck('indexname')
        ->all();

    expect($indexes)->toContain(
        'market_data_snapshots_identity_unique',
        'daily_ohlc_observations_identity_unique',
        'corporate_actions_identity_unique',
    );
});

it('creates no snapshot, prices, or corporate actions for unavailable instruments', function (): void {
    $mapping = new ProviderInstrumentMapping(
        instrument: new CanonicalInstrument('MISSING.US'),
        provider: MarketDataProviderName::YahooFinance,
        symbol: new ProviderSymbol('MISSING'),
        exchange: 'NMS',
        quoteCurrency: 'USD',
    );

    app(MarketDataPersistenceService::class)->persist(InstrumentMarketData::noData(
        $mapping,
        MarketDataError::from(MarketDataErrorCategory::InstrumentNotFound, 'not found'),
    ), '2026-08-01');

    expect(DB::table('market_data_snapshots')->count())->toBe(0)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(0)
        ->and(DB::table('corporate_actions')->count())->toBe(0);
});
