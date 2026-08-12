<?php

use App\Domain\MarketData\YahooFinanceNoDataException;
use App\Domain\MarketData\YahooFinanceProviderException;
use App\Domain\MarketData\YahooFinanceRateLimitException;
use App\Domain\MarketData\YahooFinanceTransportException;
use App\Infrastructure\MarketData\LaravelYfinanceGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('maps the internal yfinance sidecar JSON into exact-decimal payload values', function (): void {
    config()->set('services.yfinance.base_url', 'http://yfinance-web:8000');
    Http::fake([
        'http://yfinance-web:8000/v1/quotes/OTLK' => Http::response([
            'symbol' => 'OTLK',
            'exchange' => 'NCM',
            'quote_currency' => 'USD',
            'daily_ohlc' => [[
                'date' => '2026-08-12',
                'open' => '10.123456789012345678',
                'high' => '11',
                'low' => '9',
                'close' => '10.123456789012345678',
            ]],
            'dividends' => [['date' => '2026-08-01', 'amount' => '0.25']],
            'splits' => [['date' => '2026-07-01', 'ratio' => '2']],
            'retrieved_at' => '2026-08-12T10:00:00+00:00',
            'source_timezone' => 'America/New_York',
            'provider_version' => 'yfinance-0.2.66',
        ], 200),
    ]);

    $payload = app(LaravelYfinanceGateway::class)->fetch('OTLK');

    expect($payload->dailyOhlc[0]->close)->toBe('10.123456789012345678')
        ->and($payload->dividends)->toBe([['date' => '2026-08-01', 'amount' => '0.25']])
        ->and($payload->splits)->toBe([['date' => '2026-07-01', 'ratio' => '2']]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://yfinance-web:8000/v1/quotes/OTLK');
});

it('classifies a sidecar no-data response without treating it as a source observation', function (): void {
    config()->set('services.yfinance.base_url', 'http://yfinance-web:8000');
    Http::fake(['http://yfinance-web:8000/v1/quotes/MISSING' => Http::response(['error' => 'no_data'], 404)]);

    app(LaravelYfinanceGateway::class)->fetch('MISSING');
})->throws(YahooFinanceNoDataException::class);

it('classifies sidecar rate limits and connection failures as retryable gateway failures', function (): void {
    config()->set('services.yfinance.base_url', 'http://yfinance-web:8000');
    Http::fake(['http://yfinance-web:8000/v1/quotes/LIMITED' => Http::response(['error' => 'rate_limited'], 429)]);

    app(LaravelYfinanceGateway::class)->fetch('LIMITED');
})->throws(YahooFinanceRateLimitException::class);

it('fails closed when the sidecar response is malformed', function (): void {
    config()->set('services.yfinance.base_url', 'http://yfinance-web:8000');
    Http::fake(['http://yfinance-web:8000/v1/quotes/BAD' => Http::response(['symbol' => 'BAD'], 200)]);

    app(LaravelYfinanceGateway::class)->fetch('BAD');
})->throws(YahooFinanceProviderException::class);
