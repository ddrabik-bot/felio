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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

function persistenceMapping(): ProviderInstrumentMapping
{
    return new ProviderInstrumentMapping(
        instrument: new CanonicalInstrument('OTLK.US'),
        provider: MarketDataProviderName::YahooFinance,
        symbol: new ProviderSymbol('OTLK'),
        exchange: 'NCM',
        quoteCurrency: 'USD',
    );
}

function eodMarketData(array $dividends = [], array $splits = []): InstrumentMarketData
{
    return InstrumentMarketData::available(persistenceMapping(), new MarketDataSnapshot(
        exchange: 'NCM',
        quoteCurrency: 'USD',
        dailyOhlc: [new DailyOhlc('2026-07-31', '1.10000000000000000001', '1.20', '1.00', '1.15')],
        dividends: $dividends,
        splits: $splits,
        retrievedAt: '2026-08-01T21:15:00+00:00',
        sourceTimezone: 'America/New_York',
        providerVersion: 'chart-v8',
    ));
}

it('atomically upserts an EOD snapshot and exact numeric OHLC values', function (): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $service = app(MarketDataPersistenceService::class);

    $service->persist(eodMarketData(), '2026-08-01');
    $service->persist(eodMarketData(), '2026-08-01');

    expect(DB::table('market_data_snapshots')->count())->toBe(1)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(1)
        ->and(DB::table('daily_ohlc_observations')->value('open'))->toBe('1.10000000000000000001')
        ->and(collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, 'on conflict')))->toBeTrue();
});

it('preserves distinct same-day corporate actions by provider event identity', function (): void {
    app(MarketDataPersistenceService::class)->persist(eodMarketData(
        dividends: [
            ['date' => '2026-01-15', 'amount' => '0.25000000000000000001', 'provider_event_id' => 'div-101'],
            ['date' => '2026-01-15', 'amount' => '0.25000000000000000001', 'provider_event_id' => 'div-102'],
        ],
        splits: [['date' => '2026-01-15', 'ratio' => '1.5', 'provider_event_id' => 'split-301']],
    ), '2026-08-01');

    expect(DB::table('corporate_actions')->count())->toBe(3)
        ->and(DB::table('corporate_actions')->where('action_type', 'dividend')->pluck('value')->all())
        ->toBe(['0.25000000000000000001', '0.25000000000000000001']);

    $duplicate = DB::table('corporate_actions')->where('action_type', 'dividend')->first();

    expect(fn (): bool => DB::table('corporate_actions')->insert([
        'market_data_snapshot_id' => $duplicate->market_data_snapshot_id,
        'action_type' => $duplicate->action_type,
        'action_date' => $duplicate->action_date,
        'value' => $duplicate->value,
        'event_identity' => $duplicate->event_identity,
    ]))->toThrow(QueryException::class);
});

it('retains duplicate raw same-day actions without provider event IDs across a retry', function (): void {
    $service = app(MarketDataPersistenceService::class);
    $dividends = [
        ['date' => '2026-01-15', 'amount' => '0.25'],
        ['date' => '2026-01-15', 'amount' => '0.25'],
    ];

    $service->persist(eodMarketData(dividends: $dividends), '2026-08-01');
    $service->persist(eodMarketData(dividends: $dividends), '2026-08-01');

    expect(DB::table('corporate_actions')->where('action_type', 'dividend')->count())->toBe(2);
});

it('keeps one snapshot and its logical children when PostgreSQL workers persist concurrently', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');

    $barrier = tempnam(sys_get_temp_dir(), 'felio-market-data-');
    unlink($barrier);
    $instrument = 'OTLK-CONCURRENT-'.bin2hex(random_bytes(4)).'.US';
    $worker = base_path('tests/Fixtures/MarketData/PersistEodMarketDataWorker.php');
    $processes = array_map(
        static fn (): Process => new Process([PHP_BINARY, $worker, $instrument, $barrier], base_path()),
        range(1, 4),
    );

    try {
        foreach ($processes as $process) {
            $process->start();
        }

        $deadline = microtime(true) + 10;
        while (count(glob("{$barrier}.*.ready")) !== count($processes) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        expect(glob("{$barrier}.*.ready"))->toHaveCount(count($processes));

        touch($barrier);

        foreach ($processes as $process) {
            $process->wait();
            expect($process->getExitCode(), $process->getErrorOutput())->toBe(0);
        }

        $snapshotId = DB::table('market_data_snapshots')
            ->where('canonical_instrument', $instrument)
            ->value('id');

        expect(DB::table('market_data_snapshots')->where('canonical_instrument', $instrument)->count())->toBe(1)
            ->and(DB::table('daily_ohlc_observations')->where('market_data_snapshot_id', $snapshotId)->count())->toBe(1)
            ->and(DB::table('corporate_actions')->where('market_data_snapshot_id', $snapshotId)->count())->toBe(3);
    } finally {
        touch($barrier);

        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        foreach (glob("{$barrier}*") as $path) {
            unlink($path);
        }
    }
});

it('does not persist prices or actions for unavailable instruments', function (): void {
    app(MarketDataPersistenceService::class)->persist(InstrumentMarketData::unavailable(
        persistenceMapping(),
        MarketDataError::from(MarketDataErrorCategory::Transport, 'timeout'),
    ), '2026-08-01');

    expect(DB::table('market_data_snapshots')->count())->toBe(0)
        ->and(DB::table('daily_ohlc_observations')->count())->toBe(0)
        ->and(DB::table('corporate_actions')->count())->toBe(0);
});
