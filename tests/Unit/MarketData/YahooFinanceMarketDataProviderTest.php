<?php

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\MarketData\DailyOhlc;
use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataErrorCategory;
use App\Domain\MarketData\MarketDataRetryPolicy;
use App\Domain\MarketData\ProviderInstrumentMapping;
use App\Domain\MarketData\YahooFinanceGateway;
use App\Domain\MarketData\YahooFinanceInstrumentMapper;
use App\Domain\MarketData\YahooFinanceMarketDataProvider;
use App\Domain\MarketData\YahooFinanceNoDataException;
use App\Domain\MarketData\YahooFinancePayload;
use App\Domain\MarketData\YahooFinanceProviderException;
use App\Domain\MarketData\YahooFinanceRateLimitException;
use App\Domain\MarketData\YahooFinanceTransportException;

function yahooMapping(string $instrument = 'OTLK.US'): ProviderInstrumentMapping
{
    return (new YahooFinanceInstrumentMapper)->map(new CanonicalInstrument($instrument));
}

function yahooPayload(): YahooFinancePayload
{
    return new YahooFinancePayload(
        exchange: 'NCM',
        quoteCurrency: 'USD',
        dailyOhlc: [new DailyOhlc('2026-07-31', '1.10', '1.20', '1.00', '1.15')],
        dividends: [['date' => '2026-01-15', 'amount' => '0.25']],
        splits: [['date' => '2025-03-01', 'ratio' => '2']],
    );
}

it('fetches unadjusted daily OHLC, provider metadata, and corporate actions using the resolved Yahoo symbol', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public array $symbols = [];

        public function fetch(string $symbol): YahooFinancePayload
        {
            $this->symbols[] = $symbol;

            return yahooPayload();
        }
    };

    $result = (new YahooFinanceMarketDataProvider($gateway))->fetch(yahooMapping());

    expect($gateway->symbols)->toBe(['OTLK'])
        ->and($result)->toBeInstanceOf(InstrumentMarketData::class)
        ->and($result->isAvailable())->toBeTrue()
        ->and($result->snapshot->exchange)->toBe('NCM')
        ->and($result->snapshot->quoteCurrency)->toBe('USD')
        ->and($result->snapshot->dailyOhlc[0]->open)->toBe('1.10')
        ->and($result->snapshot->dailyOhlc[0]->close)->toBe('1.15')
        ->and($result->snapshot->dividends)->toBe([['date' => '2026-01-15', 'amount' => '0.25']])
        ->and($result->snapshot->splits)->toBe([['date' => '2025-03-01', 'ratio' => '2']]);
});

it('retries a transport failure only within the configured bound', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public int $attempts = 0;

        public function fetch(string $symbol): YahooFinancePayload
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new YahooFinanceTransportException('timeout');
            }

            return yahooPayload();
        }
    };

    $result = (new YahooFinanceMarketDataProvider($gateway, new MarketDataRetryPolicy(maximumAttempts: 2)))->fetch(yahooMapping());

    expect($gateway->attempts)->toBe(2)
        ->and($result->isAvailable())->toBeTrue();
});

it('returns a typed rate-limit error after bounded retries', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public int $attempts = 0;

        public function fetch(string $symbol): YahooFinancePayload
        {
            $this->attempts++;

            throw new YahooFinanceRateLimitException('HTTP 429');
        }
    };

    $result = (new YahooFinanceMarketDataProvider($gateway, new MarketDataRetryPolicy(maximumAttempts: 2)))->fetch(yahooMapping());

    expect($gateway->attempts)->toBe(2)
        ->and($result->error->category)->toBe(MarketDataErrorCategory::RateLimited)
        ->and($result->snapshot)->toBeNull();
});

it('does not retry no-data failures and classifies them independently', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public int $attempts = 0;

        public function fetch(string $symbol): YahooFinancePayload
        {
            $this->attempts++;

            throw new YahooFinanceNoDataException('empty history');
        }
    };

    $result = (new YahooFinanceMarketDataProvider($gateway))->fetch(yahooMapping());

    expect($gateway->attempts)->toBe(1)
        ->and($result->error->category)->toBe(MarketDataErrorCategory::InstrumentNotFound);
});

it('does not retry malformed provider responses', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public int $attempts = 0;

        public function fetch(string $symbol): YahooFinancePayload
        {
            $this->attempts++;

            throw new YahooFinanceProviderException('malformed provider response');
        }
    };

    $result = (new YahooFinanceMarketDataProvider($gateway))->fetch(yahooMapping());

    expect($gateway->attempts)->toBe(1)
        ->and($result->error->category)->toBe(MarketDataErrorCategory::InvalidResponse);
});

it('continues a batch when one instrument fails', function (): void {
    $gateway = new class implements YahooFinanceGateway
    {
        public function fetch(string $symbol): YahooFinancePayload
        {
            if ($symbol === 'OTLK') {
                throw new YahooFinanceTransportException('connection reset');
            }

            return yahooPayload();
        }
    };

    $provider = new YahooFinanceMarketDataProvider($gateway, new MarketDataRetryPolicy(maximumAttempts: 1));
    $results = $provider->fetchMany([yahooMapping('OTLK.US'), yahooMapping('PZU.PL')]);

    expect($results)->toHaveCount(2)
        ->and($results[0]->error->category)->toBe(MarketDataErrorCategory::Transport)
        ->and($results[1]->isAvailable())->toBeTrue()
        ->and($results[1]->mapping->symbol->value)->toBe('PZU.WA');
});
