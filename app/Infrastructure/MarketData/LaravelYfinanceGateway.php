<?php

namespace App\Infrastructure\MarketData;

use App\Domain\MarketData\DailyOhlc;
use App\Domain\MarketData\YahooFinanceGateway;
use App\Domain\MarketData\YahooFinanceNoDataException;
use App\Domain\MarketData\YahooFinancePayload;
use App\Domain\MarketData\YahooFinanceProviderException;
use App\Domain\MarketData\YahooFinanceRateLimitException;
use App\Domain\MarketData\YahooFinanceTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class LaravelYfinanceGateway implements YahooFinanceGateway
{
    public function fetch(string $symbol): YahooFinancePayload
    {
        try {
            $response = Http::acceptJson()
                ->withUserAgent('Felio-yfinance-sidecar/1.0')
                ->connectTimeout(5)
                ->timeout(20)
                ->get(rtrim((string) config('services.yfinance.base_url'), '/').'/v1/quotes/'.rawurlencode($symbol));
        } catch (ConnectionException $exception) {
            throw new YahooFinanceTransportException('yfinance sidecar connection failed', previous: $exception);
        }

        if ($response->status() === 404) {
            throw new YahooFinanceNoDataException('yfinance sidecar reported no data');
        }
        if ($response->status() === 429) {
            throw new YahooFinanceRateLimitException('yfinance sidecar rate limited the request');
        }
        if (! $response->successful()) {
            throw new YahooFinanceTransportException('yfinance sidecar returned HTTP '.$response->status());
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new YahooFinanceProviderException('yfinance sidecar returned a non-object JSON payload');
        }

        try {
            return new YahooFinancePayload(
                $this->requiredString($payload, 'exchange'),
                $this->requiredString($payload, 'quote_currency'),
                $this->dailyOhlc($payload),
                $this->events($payload, 'dividends', 'amount'),
                $this->events($payload, 'splits', 'ratio'),
                $this->requiredString($payload, 'retrieved_at'),
                $this->requiredString($payload, 'source_timezone'),
                $this->requiredString($payload, 'provider_version'),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new YahooFinanceProviderException('yfinance sidecar returned invalid payload: '.$exception->getMessage(), previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload @return list<DailyOhlc> */
    private function dailyOhlc(array $payload): array
    {
        if (! isset($payload['daily_ohlc']) || ! is_array($payload['daily_ohlc']) || $payload['daily_ohlc'] === []) {
            throw new \InvalidArgumentException('daily_ohlc is required');
        }

        return array_map(function (mixed $row): DailyOhlc {
            if (! is_array($row)) {
                throw new \InvalidArgumentException('daily_ohlc row is invalid');
            }

            return new DailyOhlc(
                $this->requiredDate($row, 'date'),
                $this->requiredDecimal($row, 'open'),
                $this->requiredDecimal($row, 'high'),
                $this->requiredDecimal($row, 'low'),
                $this->requiredDecimal($row, 'close'),
            );
        }, $payload['daily_ohlc']);
    }

    /** @param array<string, mixed> $payload @return list<array{date: string, amount?: string, ratio?: string}> */
    private function events(array $payload, string $key, string $valueKey): array
    {
        if (! isset($payload[$key]) || ! is_array($payload[$key])) {
            throw new \InvalidArgumentException($key.' is required');
        }

        return array_map(function (mixed $row) use ($valueKey): array {
            if (! is_array($row)) {
                throw new \InvalidArgumentException('event row is invalid');
            }

            return ['date' => $this->requiredDate($row, 'date'), $valueKey => $this->requiredDecimal($row, $valueKey)];
        }, $payload[$key]);
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException($key.' is required');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredDate(array $payload, string $key): string
    {
        $value = $this->requiredString($payload, $key);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException($key.' must be ISO date');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredDecimal(array $payload, string $key): string
    {
        $value = $this->requiredString($payload, $key);
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException($key.' must be an exact decimal string');
        }

        return $value;
    }
}
