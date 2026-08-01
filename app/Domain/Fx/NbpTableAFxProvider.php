<?php

namespace App\Domain\Fx;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final readonly class NbpTableAFxProvider implements FxRateProvider
{
    private const API_BASE_URL = 'https://api.nbp.pl/api/exchangerates/rates';

    private const TABLE = 'A';

    private const SOURCE_TIMEZONE = 'Europe/Warsaw';

    private const IMPLEMENTATION_VERSION = 'nbp-table-a-v1';

    private const API_CONTRACT = 'unversioned';

    /** @var Closure(): DateTimeImmutable */
    private Closure $now;

    /** @param callable(): DateTimeImmutable|null $now */
    public function __construct(
        private NbpFxGateway $gateway,
        private NbpFxRetryPolicy $retryPolicy = new NbpFxRetryPolicy,
        ?callable $now = null,
    ) {
        $this->now = Closure::fromCallable($now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now'));
    }

    public function historical(string $currency, DateTimeImmutable $requestedDate): FxRateResult
    {
        return $this->fetch($currency, $requestedDate, true);
    }

    public function current(string $currency, DateTimeImmutable $asOfDate): FxRateResult
    {
        return $this->fetch($currency, $asOfDate, false);
    }

    /**
     * @param  list<string>  $currencies
     * @return list<FxRateResult>
     */
    public function historicalMany(array $currencies, DateTimeImmutable $requestedDate): array
    {
        return array_map(fn (string $currency): FxRateResult => $this->historical($currency, $requestedDate), $currencies);
    }

    private function fetch(string $currency, DateTimeImmutable $requestedDate, bool $historical): FxRateResult
    {
        $currency = strtoupper($currency);
        $endpoint = $this->endpoint($currency, $requestedDate, $historical);
        $retrievedAt = ($this->now)();
        $attempt = 0;

        do {
            $attempt++;

            try {
                $response = $this->gateway->get($endpoint);
            } catch (NbpFxTransportException) {
                $reason = 'transport_error';
                $retryable = true;
            } catch (Throwable) {
                return $this->unavailable($currency, $requestedDate, 'gateway_error', $attempt, $retrievedAt, $endpoint);
            }

            if (isset($response)) {
                if ($response->statusCode === 404) {
                    return $this->unavailable($currency, $requestedDate, 'not_found', $attempt, $retrievedAt, $endpoint);
                }

                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    return $this->parse($currency, $requestedDate, $historical, $response->body, $attempt, $retrievedAt, $endpoint);
                }

                $reason = sprintf('http_%d', $response->statusCode);
                $retryable = in_array($response->statusCode, [408, 425, 429, 500, 502, 503, 504], true);
                unset($response);
            }
        } while ($this->retryPolicy->shouldRetry($retryable, $attempt));

        return $this->unavailable($currency, $requestedDate, $reason, $attempt, $retrievedAt, $endpoint);
    }

    private function endpoint(string $currency, DateTimeImmutable $requestedDate, bool $historical): string
    {
        if (! $historical) {
            return sprintf('%s/%s/%s/?format=json', self::API_BASE_URL, self::TABLE, $currency);
        }

        $date = $requestedDate->setTimezone(new DateTimeZone(self::SOURCE_TIMEZONE))->format('Y-m-d');

        return sprintf('%s/%s/%s/%s/%s/?format=json', self::API_BASE_URL, self::TABLE, $currency, $date, $date);
    }

    private function parse(
        string $currency,
        DateTimeImmutable $requestedDate,
        bool $historical,
        string $body,
        int $attempts,
        DateTimeImmutable $retrievedAt,
        string $endpoint,
    ): FxRateResult {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unavailable($currency, $requestedDate, 'invalid_json', $attempts, $retrievedAt, $endpoint);
        }

        if (! is_array($payload) || ($payload['table'] ?? null) !== self::TABLE || ($payload['code'] ?? null) !== $currency) {
            return $this->unavailable($currency, $requestedDate, 'invalid_payload', $attempts, $retrievedAt, $endpoint);
        }

        $rates = $payload['rates'] ?? null;
        if (! is_array($rates) || count($rates) !== 1 || ! is_array($rates[0])) {
            return $this->unavailable($currency, $requestedDate, 'unexpected_rate_count', $attempts, $retrievedAt, $endpoint);
        }

        $rate = $rates[0];
        $effectiveDate = isset($rate['effectiveDate']) ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $rate['effectiveDate']) : false;
        $mid = $rate['mid'] ?? null;
        if ($effectiveDate === false || ! is_string($mid) && ! is_int($mid) && ! is_float($mid)) {
            return $this->unavailable($currency, $requestedDate, 'invalid_rate_fields', $attempts, $retrievedAt, $endpoint);
        }

        $mid = (string) $mid;
        if (! preg_match('/^\d+(?:\.\d+)?$/', $mid) || bccomp($mid, '0', max(0, strlen(strrchr($mid, '.') ?: '') - 1)) <= 0) {
            return $this->unavailable($currency, $requestedDate, 'invalid_mid', $attempts, $retrievedAt, $endpoint);
        }

        $requestedDay = $requestedDate->setTimezone(new DateTimeZone(self::SOURCE_TIMEZONE))->format('Y-m-d');
        $effectiveDay = $effectiveDate->format('Y-m-d');
        if ($historical && $effectiveDay !== $requestedDay) {
            return $this->unavailable($currency, $requestedDate, 'effective_date_mismatch', $attempts, $retrievedAt, $endpoint);
        }

        if (! $historical && $effectiveDay > $requestedDay) {
            return $this->unavailable($currency, $requestedDate, 'effective_date_after_as_of_date', $attempts, $retrievedAt, $endpoint);
        }

        $availability = $effectiveDay < $requestedDay ? FxRateAvailability::Stale : FxRateAvailability::Available;
        $reason = $availability === FxRateAvailability::Stale ? 'effective_date_before_as_of_date' : null;

        return new FxRateResult(
            $currency,
            $requestedDate,
            $effectiveDate,
            $availability,
            $mid,
            $reason,
            $attempts,
            $retrievedAt,
            $endpoint,
            self::TABLE,
            isset($rate['no']) ? (string) $rate['no'] : null,
            self::SOURCE_TIMEZONE,
            self::IMPLEMENTATION_VERSION,
            self::API_CONTRACT,
        );
    }

    private function unavailable(
        string $currency,
        DateTimeImmutable $requestedDate,
        string $reason,
        int $attempts,
        DateTimeImmutable $retrievedAt,
        string $endpoint,
    ): FxRateResult {
        return new FxRateResult(
            $currency,
            $requestedDate,
            null,
            FxRateAvailability::Unavailable,
            null,
            $reason,
            $attempts,
            $retrievedAt,
            $endpoint,
            self::TABLE,
            null,
            self::SOURCE_TIMEZONE,
            self::IMPLEMENTATION_VERSION,
            self::API_CONTRACT,
        );
    }
}
