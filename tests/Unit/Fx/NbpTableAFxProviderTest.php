<?php

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\NbpFxGateway;
use App\Domain\Fx\NbpFxHttpResponse;
use App\Domain\Fx\NbpFxRetryPolicy;
use App\Domain\Fx\NbpFxTransportException;
use App\Domain\Fx\NbpTableAFxProvider;

function nbpPayload(string $currency, string $effectiveDate, string $mid, string $table = 'A'): string
{
    return json_encode([
        'table' => $table,
        'currency' => 'test currency',
        'code' => $currency,
        'rates' => [[
            'no' => '147/A/NBP/2026',
            'effectiveDate' => $effectiveDate,
            'mid' => $mid,
        ]],
    ], JSON_THROW_ON_ERROR);
}

function nbpProvider(NbpFxGateway $gateway, int $maximumAttempts = 2): NbpTableAFxProvider
{
    return new NbpTableAFxProvider(
        gateway: $gateway,
        retryPolicy: new NbpFxRetryPolicy($maximumAttempts),
        now: fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-01T12:00:00+00:00'),
    );
}

it('returns unavailable for an exact-date weekend 404 without substituting a prior business day', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public array $urls = [];

        public function get(string $url): NbpFxHttpResponse
        {
            $this->urls[] = $url;

            return new NbpFxHttpResponse(404, '');
        }
    };

    $result = nbpProvider($gateway)->historical('usd', new DateTimeImmutable('2026-08-01'));

    expect($gateway->urls)->toBe(['https://api.nbp.pl/api/exchangerates/rates/A/USD/2026-08-01/2026-08-01/?format=json'])
        ->and($result->availability)->toBe(FxRateAvailability::Unavailable)
        ->and($result->plnPerUnit)->toBeNull()
        ->and($result->effectiveDate)->toBeNull()
        ->and($result->reason)->toBe('not_found')
        ->and($result->attempts)->toBe(1);
});

it('returns stale for a current response older than the Warsaw as-of date', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public function get(string $url): NbpFxHttpResponse
        {
            return new NbpFxHttpResponse(200, nbpPayload('USD', '2026-07-31', '3.7425'));
        }
    };

    $result = nbpProvider($gateway)->current('USD', new DateTimeImmutable('2026-08-01'));

    expect($result->availability)->toBe(FxRateAvailability::Stale)
        ->and($result->plnPerUnit)->toBe('3.7425')
        ->and($result->effectiveDate?->format('Y-m-d'))->toBe('2026-07-31')
        ->and($result->reason)->toBe('effective_date_before_as_of_date')
        ->and($result->sourceTimezone)->toBe('Europe/Warsaw')
        ->and($result->apiEndpoint)->toBe('https://api.nbp.pl/api/exchangerates/rates/A/USD/?format=json');
});

it('returns unavailable when a current response is dated after the Warsaw as-of date', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public function get(string $url): NbpFxHttpResponse
        {
            return new NbpFxHttpResponse(200, nbpPayload('USD', '2026-08-02', '3.7425'));
        }
    };

    $result = nbpProvider($gateway)->current('USD', new DateTimeImmutable('2026-08-01'));

    expect($result->availability)->toBe(FxRateAvailability::Unavailable)
        ->and($result->plnPerUnit)->toBeNull()
        ->and($result->reason)->toBe('effective_date_after_as_of_date');
});

it('returns unavailable for malformed provider data', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public function get(string $url): NbpFxHttpResponse
        {
            return new NbpFxHttpResponse(200, '{not-json');
        }
    };

    $result = nbpProvider($gateway)->historical('EUR', new DateTimeImmutable('2026-07-31'));

    expect($result->availability)->toBe(FxRateAvailability::Unavailable)
        ->and($result->reason)->toBe('invalid_json')
        ->and($result->attempts)->toBe(1);
});

it('retries only transient transport and HTTP failures within its configured bound', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public int $attempts = 0;

        public function get(string $url): NbpFxHttpResponse
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new NbpFxTransportException('timeout');
            }

            return new NbpFxHttpResponse(503, '');
        }
    };

    $result = nbpProvider($gateway, 2)->historical('EUR', new DateTimeImmutable('2026-07-31'));

    expect($gateway->attempts)->toBe(2)
        ->and($result->availability)->toBe(FxRateAvailability::Unavailable)
        ->and($result->reason)->toBe('http_503')
        ->and($result->attempts)->toBe(2);
});

it('preserves the NBP decimal string and response metadata exactly', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public function get(string $url): NbpFxHttpResponse
        {
            return new NbpFxHttpResponse(200, nbpPayload('EUR', '2026-07-31', '4.312800'));
        }
    };

    $result = nbpProvider($gateway)->historical('EUR', new DateTimeImmutable('2026-07-31'));

    expect($result->availability)->toBe(FxRateAvailability::Available)
        ->and($result->plnPerUnit)->toBe('4.312800')
        ->and($result->table)->toBe('A')
        ->and($result->tableNumber)->toBe('147/A/NBP/2026')
        ->and($result->providerImplementationVersion)->toBe('nbp-table-a-v1')
        ->and($result->providerApiContract)->toBe('unversioned')
        ->and($result->retrievedAt->format(DATE_ATOM))->toBe('2026-08-01T12:00:00+00:00')
        ->and($result->effectiveDate?->format('Y-m-d'))->toBe('2026-07-31');
});

it('isolates each currency so a failed USD request does not block EUR', function (): void {
    $gateway = new class implements NbpFxGateway
    {
        public function get(string $url): NbpFxHttpResponse
        {
            if (str_contains($url, '/USD/')) {
                throw new NbpFxTransportException('connection reset');
            }

            return new NbpFxHttpResponse(200, nbpPayload('EUR', '2026-07-31', '4.3128'));
        }
    };

    $results = nbpProvider($gateway, 1)->historicalMany(['USD', 'EUR'], new DateTimeImmutable('2026-07-31'));

    expect($results)->toHaveCount(2)
        ->and($results[0]->currency)->toBe('USD')
        ->and($results[0]->availability)->toBe(FxRateAvailability::Unavailable)
        ->and($results[0]->reason)->toBe('transport_error')
        ->and($results[1]->currency)->toBe('EUR')
        ->and($results[1]->availability)->toBe(FxRateAvailability::Available)
        ->and($results[1]->plnPerUnit)->toBe('4.3128');
});
