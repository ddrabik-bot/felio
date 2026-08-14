<?php

use App\Domain\MarketData\AvailabilityStatus;
use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\MarketData\InstrumentAvailability;
use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataError;
use App\Domain\MarketData\MarketDataErrorCategory;
use App\Domain\MarketData\MarketDataProvider;
use App\Domain\MarketData\MarketDataProviderName;
use App\Domain\MarketData\MarketDataRetryPolicy;
use App\Domain\MarketData\ProviderInstrumentMapping;
use App\Domain\MarketData\ProviderSymbol;
use App\Domain\MarketData\YahooFinanceInstrumentMapper;

it('maps the OTLK broker symbol to its Yahoo Finance symbol without changing Felio identity', function (): void {
    $mapping = (new YahooFinanceInstrumentMapper)->map(new CanonicalInstrument('OTLK.US'));

    expect($mapping->instrument->value)->toBe('OTLK.US')
        ->and($mapping->provider)->toBe(MarketDataProviderName::YahooFinance)
        ->and($mapping->symbol->value)->toBe('OTLK')
        ->and($mapping->exchange)->toBe('NCM')
        ->and($mapping->quoteCurrency)->toBe('USD');
});

it('maps the verified affected broker symbols to their exact Yahoo Finance instruments', function (): void {
    $mapper = new YahooFinanceInstrumentMapper;

    foreach ([
        'ARMG.UK' => ['ARMG.L', 'LSE', 'GBP'],
        'SPCE.US' => ['SPCE', 'NYQ', 'USD'],
        'SPXC.US' => ['SPXC', 'NYQ', 'USD'],
    ] as $instrument => [$symbol, $exchange, $currency]) {
        $mapping = $mapper->map(new CanonicalInstrument($instrument));

        expect($mapping->instrument->value)->toBe($instrument)
            ->and($mapping->provider)->toBe(MarketDataProviderName::YahooFinance)
            ->and($mapping->symbol->value)->toBe($symbol)
            ->and($mapping->exchange)->toBe($exchange)
            ->and($mapping->quoteCurrency)->toBe($currency);
    }
});

it('maps Polish instruments to the explicit Warsaw provider suffix', function (): void {
    $mapping = (new YahooFinanceInstrumentMapper)->map(new CanonicalInstrument('PZU.PL'));

    expect($mapping->instrument->value)->toBe('PZU.PL')
        ->and($mapping->symbol->value)->toBe('PZU.WA')
        ->and($mapping->exchange)->toBe('WSE')
        ->and($mapping->quoteCurrency)->toBe('PLN');
});

it('keeps German provider symbols explicit and records their metadata', function (): void {
    $mapping = (new YahooFinanceInstrumentMapper)->map(new CanonicalInstrument('SAP.DE'));

    expect($mapping->instrument->value)->toBe('SAP.DE')
        ->and($mapping->symbol->value)->toBe('SAP.DE')
        ->and($mapping->exchange)->toBe('GER')
        ->and($mapping->quoteCurrency)->toBe('EUR');
});

it('does not silently substitute a provider when an instrument has no mapping', function (): void {
    expect((new YahooFinanceInstrumentMapper)->map(new CanonicalInstrument('AAPL.US')))->toBeNull();
});

it('returns a typed availability result for one mapped instrument', function (): void {
    $mapping = new ProviderInstrumentMapping(
        new CanonicalInstrument('OTLK.US'),
        MarketDataProviderName::YahooFinance,
        new ProviderSymbol('OTLK'),
        'NCM',
        'USD',
    );

    $provider = new class implements MarketDataProvider
    {
        public function availability(ProviderInstrumentMapping $mapping): InstrumentAvailability
        {
            return InstrumentAvailability::available($mapping);
        }

        public function fetch(ProviderInstrumentMapping $mapping): InstrumentMarketData
        {
            throw new LogicException('This availability-only fake must not fetch market data.');
        }
    };

    $result = $provider->availability($mapping);

    expect($result->status)->toBe(AvailabilityStatus::Available)
        ->and($result->mapping)->toBe($mapping)
        ->and($result->error)->toBeNull();
});

it('limits retries to transient provider failures', function (): void {
    $policy = new MarketDataRetryPolicy(maximumAttempts: 2);

    expect(MarketDataError::from(MarketDataErrorCategory::Transport, 'timeout')->retryable())->toBeTrue()
        ->and(MarketDataError::from(MarketDataErrorCategory::RateLimited, 'too many requests')->retryable())->toBeTrue()
        ->and(MarketDataError::from(MarketDataErrorCategory::InstrumentNotFound, 'unknown')->retryable())->toBeFalse()
        ->and(MarketDataError::from(MarketDataErrorCategory::InvalidResponse, 'malformed')->retryable())->toBeFalse()
        ->and($policy->shouldRetry(MarketDataError::from(MarketDataErrorCategory::Transport, 'timeout'), 1))->toBeTrue()
        ->and($policy->shouldRetry(MarketDataError::from(MarketDataErrorCategory::Transport, 'timeout'), 2))->toBeFalse();
});
