<?php

use App\Application\Portfolio\PortfolioValuationService;
use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\Portfolio\PortfolioValuationReadRepository;
use App\Domain\Valuation\StaleFxRatePolicy;
use App\Domain\Valuation\ValuationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

function importPosition(string $batch, string $asOf, string $instrument, string $quantity): void
{
    app(PortfolioImportService::class)->persist(new ImportBatch('xtb', 'valuation-account', $batch, new DateTimeImmutable($asOf)), [
        PortfolioImportRow::valid("{$batch}-{$instrument}", new CanonicalInstrument($instrument), $quantity, null, new DateTimeImmutable($asOf), ['instrument' => $instrument, 'quantity' => $quantity]),
    ]);
}
function price(string $instrument, string $date, string $currency, string $close, string $provider = 'provider-a'): void
{
    $id = DB::table('market_data_snapshots')->insertGetId(['canonical_instrument' => $instrument, 'provider' => $provider, 'provider_symbol' => $instrument, 'exchange' => 'XWAR', 'quote_currency' => $currency, 'session_date' => $date, 'retrieved_at' => now(), 'source_timezone' => 'Europe/Warsaw', 'provider_version' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('daily_ohlc_observations')->insert(['market_data_snapshot_id' => $id, 'trading_date' => $date, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'created_at' => now(), 'updated_at' => now()]);
}
function fx(string $currency, string $date, string $availability, ?string $rate, string $identity): void
{
    DB::table('fx_rate_snapshots')->insert(['provider_implementation_version' => 'nbp-test', 'currency' => $currency, 'requested_date' => $date, 'effective_date' => $availability === 'stale' ? '2026-01-01' : $date, 'availability' => $availability, 'pln_per_unit' => $rate, 'reason' => $availability === 'stale' ? 'source_stale' : null, 'attempts' => 1, 'retrieved_at' => now(), 'api_endpoint' => 'https://example.test/fx', 'table' => 'A', 'source_timezone' => 'Europe/Warsaw', 'provider_response_metadata' => '{}', 'source_observation_identity' => hash('sha256', $identity), 'created_at' => now(), 'updated_at' => now()]);
}
function valuation(StaleFxRatePolicy $policy = StaleFxRatePolicy::Reject): PortfolioValuationService
{
    return new PortfolioValuationService(app(PortfolioValuationReadRepository::class), new ValuationService($policy));
}

function valuationAccountId(): int
{
    return (int) DB::table('portfolio_accounts')
        ->where('broker', 'xtb')
        ->where('account_reference', 'valuation-account')
        ->value('id');
}

it('reads a historical source-row position as of an explicit date after a later import overwrites the current projection', function (): void {
    importPosition('batch-one', '2026-01-02T00:00:00+01:00', 'PZU.PL', '2');
    importPosition('batch-two', '2026-01-03T00:00:00+01:00', 'PZU.PL', '5');
    price('PZU.PL', '2026-01-02', 'PLN', '10');

    $read = valuation()->read(new DateTimeImmutable('2026-01-02T20:00:00+01:00'), valuationAccountId());

    expect(DB::table('portfolio_positions')->value('quantity'))->toBe('5')
        ->and($read->rows)->toHaveCount(1)
        ->and($read->rows[0]->quantity)->toBe('2')
        ->and($read->rows[0]->plnGrosze)->toBe(2000)
        ->and($read->totalPlnGrosze)->toBe(2000);
});

it('reads the same aggregate XTB trade position in historical valuation instead of using a last source row', function (): void {
    $rows = [];
    for ($index = 1; $index <= 22; $index++) {
        $quantity = $index <= 16 ? '2' : '1';
        $price = $index <= 16 ? 1000 : 2000;
        $rows[] = PortfolioImportRow::valid("xtb-history-{$index}", new CanonicalInstrument('XTB.PL'), $quantity, $price, new DateTimeImmutable(sprintf('2026-08-%02dT00:00:00+00:00', $index)), ['xtb_operation' => 'buy', 'xtb_price' => (string) bcdiv((string) $price, '100', 2)]);
    }

    app(PortfolioImportService::class)->persist(new ImportBatch('xtb', 'valuation-account', 'xtb-history-aggregate', new DateTimeImmutable('2026-08-22T00:00:00+00:00')), $rows);

    $read = valuation()->read(new DateTimeImmutable('2026-08-22T20:00:00+00:00'), valuationAccountId());

    expect($read->rows)->toHaveCount(1)
        ->and($read->rows[0]->quantity)->toBe('38');
});

it('forward-fills a weekend valuation from the latest price and FX observations', function (): void {
    importPosition('weekend-foreign', '2026-08-14T00:00:00+02:00', 'ACME.US', '3');
    price('ACME.US', '2026-08-14', 'USD', '10.25');
    fx('USD', '2026-08-14', 'available', '4.012345678901234567', 'weekend-friday');

    $read = valuation()->read(new DateTimeImmutable('2026-08-15T20:00:00+02:00'), valuationAccountId());

    expect($read->rows[0]->plnGrosze)->toBe(12338)
        ->and($read->rows[0]->sourcePrice->usedDate?->format('Y-m-d'))->toBe('2026-08-14')
        ->and($read->rows[0]->sourcePrice->availability->value)->toBe('stale')
        ->and($read->rows[0]->fxRate?->requestedDate->format('Y-m-d'))->toBe('2026-08-15')
        ->and($read->rows[0]->fxRate?->effectiveDate?->format('Y-m-d'))->toBe('2026-08-14')
        ->and($read->rows[0]->fxRate?->availability->value)->toBe('available')
        ->and($read->totalPlnGrosze)->toBe(12338);
});

it('forward-fills from the prior trading session when no observation exists on a weekday', function (): void {
    importPosition('prior-session', '2026-08-14T00:00:00+02:00', 'PZU.PL', '2');
    price('PZU.PL', '2026-08-14', 'PLN', '10');

    $read = valuation()->read(new DateTimeImmutable('2026-08-17T20:00:00+02:00'), valuationAccountId());

    expect($read->rows[0]->plnGrosze)->toBe(2000)
        ->and($read->rows[0]->sourcePrice->usedDate?->format('Y-m-d'))->toBe('2026-08-14')
        ->and($read->rows[0]->sourcePrice->availability->value)->toBe('stale')
        ->and($read->totalPlnGrosze)->toBe(2000);
});

it('remains unavailable when no earlier market or FX observation exists', function (): void {
    importPosition('foreign', '2026-01-02T00:00:00+01:00', 'ACME.US', '3');

    $read = valuation()->read(new DateTimeImmutable('2026-01-02T20:00:00+01:00'), valuationAccountId());

    expect($read->rows[0]->plnGrosze)->toBeNull()
        ->and($read->rows[0]->diagnostics)->toContain('price_unavailable:market_price_missing_on_or_before_date')
        ->and($read->totalPlnGrosze)->toBe(0);
});

it('applies stale FX policy and rejects ambiguous exact-date prices deterministically', function (): void {
    importPosition('stale', '2026-01-02T00:00:00+01:00', 'OTLK.US', '2');
    price('OTLK.US', '2026-01-02', 'USD', '5');
    fx('USD', '2026-01-02', 'stale', '4', 'one');
    $rejected = valuation()->read(new DateTimeImmutable('2026-01-02T20:00:00+01:00'), valuationAccountId());
    $accepted = valuation(StaleFxRatePolicy::Accept)->read(new DateTimeImmutable('2026-01-02T20:00:00+01:00'), valuationAccountId());

    expect($rejected->rows[0]->plnGrosze)->toBeNull()
        ->and($rejected->rows[0]->diagnostics)->toContain('fx_rate_stale_rejected:source_stale')
        ->and($accepted->rows[0]->plnGrosze)->toBe(4000)
        ->and($accepted->rows[0]->availability->value)->toBe('stale');
});

it('rejects checked portfolio totals that would overflow PHP integers', function (): void {
    importPosition('overflow-one', '2026-01-02T00:00:00+01:00', 'AAA.PL', '1');
    importPosition('overflow-two', '2026-01-02T00:00:00+01:00', 'BBB.PL', '1');
    price('AAA.PL', '2026-01-02', 'PLN', '92233720368547758.07');
    price('BBB.PL', '2026-01-02', 'PLN', '0.01');

    expect(fn () => valuation()->read(new DateTimeImmutable('2026-01-02T20:00:00+01:00'), valuationAccountId()))->toThrow(OverflowException::class, 'Portfolio PLN grosze total exceeds PHP integer range.');
});
