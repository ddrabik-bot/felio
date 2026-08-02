<?php

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRateResult;
use App\Domain\Valuation\PriceQuote;
use App\Domain\Valuation\StaleFxRatePolicy;
use App\Domain\Valuation\ValuationAvailability;
use App\Domain\Valuation\ValuationInput;
use App\Domain\Valuation\ValuationService;

it('values an available PLN price without an FX rate', function (): void {
    $result = (new ValuationService)->value(new ValuationInput(
        quantity: '2.5',
        price: new PriceQuote('PLN', '123.4567', ValuationAvailability::Available),
    ));

    expect($result->availability)->toBe(ValuationAvailability::Available)
        ->and($result->marketValuePln)->toBe('308.64')
        ->and($result->diagnostics)->toBe([]);
});

it('converts an available USD market value to PLN using the exact FX rate', function (): void {
    $result = (new ValuationService)->value(new ValuationInput(
        quantity: '12.3456789',
        price: new PriceQuote('USD', '98.7654321', ValuationAvailability::Available),
        fxRate: fxRate('USD', FxRateAvailability::Available, '3.987654321'),
    ));

    expect($result->availability)->toBe(ValuationAvailability::Available)
        ->and($result->marketValuePln)->toBe('4862.25')
        ->and($result->diagnostics)->toBe([]);
});

it('rejects stale FX by default and allows it only under the explicit acceptance policy', function (): void {
    $input = new ValuationInput(
        quantity: '10',
        price: new PriceQuote('EUR', '10', ValuationAvailability::Available),
        fxRate: fxRate('EUR', FxRateAvailability::Stale, '4.1234', 'effective_date_before_as_of_date'),
    );

    $rejected = (new ValuationService)->value($input);
    $accepted = (new ValuationService(StaleFxRatePolicy::Accept))->value($input);

    expect($rejected->availability)->toBe(ValuationAvailability::Unavailable)
        ->and($rejected->marketValuePln)->toBeNull()
        ->and($rejected->diagnostics)->toBe(['fx_rate_stale_rejected:effective_date_before_as_of_date'])
        ->and($accepted->availability)->toBe(ValuationAvailability::Stale)
        ->and($accepted->marketValuePln)->toBe('412.34')
        ->and($accepted->diagnostics)->toBe(['fx_rate_stale_accepted:effective_date_before_as_of_date']);
});

it('rounds only the final PLN value to grosz after preserving source precision', function (): void {
    $service = new ValuationService;

    $belowHalf = $service->value(new ValuationInput('1', new PriceQuote('PLN', '1.004999999999999999999', ValuationAvailability::Available)));
    $atHalf = $service->value(new ValuationInput('1', new PriceQuote('PLN', '1.005000000000000000001', ValuationAvailability::Available)));
    $exact = $service->value(new ValuationInput('0.1', new PriceQuote('EUR', '0.2', ValuationAvailability::Available), fxRate('EUR', FxRateAvailability::Available, '4.333333333333333333333')));

    expect($belowHalf->marketValuePln)->toBe('1.00')
        ->and($atHalf->marketValuePln)->toBe('1.01')
        ->and($exact->marketValuePln)->toBe('0.09');
});

it('rejects zero and negative quantity or price decimal inputs', function (): void {
    expect(fn (): ValuationInput => new ValuationInput('0', new PriceQuote('PLN', '1', ValuationAvailability::Available)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ValuationInput => new ValuationInput('-1', new PriceQuote('PLN', '1', ValuationAvailability::Available)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): PriceQuote => new PriceQuote('PLN', '0', ValuationAvailability::Available))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): PriceQuote => new PriceQuote('PLN', '-1', ValuationAvailability::Available))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ValuationInput => new ValuationInput('1', new PriceQuote('EUR', '1', ValuationAvailability::Available), fxRate('EUR', FxRateAvailability::Available, '0')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ValuationInput => new ValuationInput('1', new PriceQuote('EUR', '1', ValuationAvailability::Available), fxRate('EUR', FxRateAvailability::Available, '-4.2')))
        ->toThrow(InvalidArgumentException::class);
});

it('propagates unavailable price and FX states without inventing a PLN value', function (): void {
    $priceUnavailable = (new ValuationService)->value(new ValuationInput(
        quantity: '1',
        price: new PriceQuote('USD', '10', ValuationAvailability::Unavailable, 'market_closed'),
    ));
    $fxUnavailable = (new ValuationService)->value(new ValuationInput(
        quantity: '1',
        price: new PriceQuote('USD', '10', ValuationAvailability::Available),
        fxRate: fxRate('USD', FxRateAvailability::Unavailable, null, 'not_found'),
    ));

    expect($priceUnavailable->availability)->toBe(ValuationAvailability::Unavailable)
        ->and($priceUnavailable->marketValuePln)->toBeNull()
        ->and($priceUnavailable->diagnostics)->toBe(['price_unavailable:market_closed'])
        ->and($fxUnavailable->availability)->toBe(ValuationAvailability::Unavailable)
        ->and($fxUnavailable->marketValuePln)->toBeNull()
        ->and($fxUnavailable->diagnostics)->toBe(['fx_rate_unavailable:not_found']);
});

it('propagates a stale price state when the required FX rate is available', function (): void {
    $result = (new ValuationService)->value(new ValuationInput(
        quantity: '2',
        price: new PriceQuote('EUR', '10', ValuationAvailability::Stale, 'market_session_not_current'),
        fxRate: fxRate('EUR', FxRateAvailability::Available, '4'),
    ));

    expect($result->availability)->toBe(ValuationAvailability::Stale)
        ->and($result->marketValuePln)->toBe('80.00')
        ->and($result->diagnostics)->toBe(['market_session_not_current']);
});

function fxRate(string $currency, FxRateAvailability $availability, ?string $plnPerUnit, ?string $reason = null): FxRateResult
{
    return new FxRateResult(
        currency: $currency,
        requestedDate: new DateTimeImmutable('2026-08-01'),
        effectiveDate: new DateTimeImmutable('2026-08-01'),
        availability: $availability,
        plnPerUnit: $plnPerUnit,
        reason: $reason,
        attempts: 1,
        retrievedAt: new DateTimeImmutable('2026-08-01T12:00:00+00:00'),
        apiEndpoint: 'https://example.test/fx',
        table: 'A',
        tableNumber: '1/A/NBP/2026',
        sourceTimezone: 'Europe/Warsaw',
        providerImplementationVersion: 'test-v1',
        providerApiContract: 'test',
    );
}
