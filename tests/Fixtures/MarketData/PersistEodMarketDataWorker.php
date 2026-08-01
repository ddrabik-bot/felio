<?php

declare(strict_types=1);

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\MarketData\DailyOhlc;
use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataPersistenceService;
use App\Domain\MarketData\MarketDataProviderName;
use App\Domain\MarketData\MarketDataSnapshot;
use App\Domain\MarketData\ProviderInstrumentMapping;
use App\Domain\MarketData\ProviderSymbol;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $instrument, $barrier] = $argv;

$mapping = new ProviderInstrumentMapping(
    instrument: new CanonicalInstrument($instrument),
    provider: MarketDataProviderName::YahooFinance,
    symbol: new ProviderSymbol('OTLK'),
    exchange: 'NCM',
    quoteCurrency: 'USD',
);

$marketData = InstrumentMarketData::available($mapping, new MarketDataSnapshot(
    exchange: 'NCM',
    quoteCurrency: 'USD',
    dailyOhlc: [new DailyOhlc('2026-07-31', '1.10000000000000000001', '1.20', '1.00', '1.15')],
    dividends: [
        ['date' => '2026-01-15', 'amount' => '0.25'],
        ['date' => '2026-01-15', 'amount' => '0.25'],
    ],
    splits: [['date' => '2026-01-15', 'ratio' => '1.5']],
    retrievedAt: '2026-08-01T21:15:00+00:00',
    sourceTimezone: 'America/New_York',
    providerVersion: 'chart-v8',
));

touch(sprintf('%s.%d.ready', $barrier, getmypid()));

while (! file_exists($barrier)) {
    usleep(1_000);
}

$app->make(MarketDataPersistenceService::class)->persist($marketData, '2026-08-01');
