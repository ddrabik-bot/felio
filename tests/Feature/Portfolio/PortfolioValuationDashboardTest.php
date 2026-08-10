<?php

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $user = User::factory()->create();
    DB::table('portfolio_accounts')->insert([
        'user_id' => $user->id,
        'broker' => 'xtb',
        'account_reference' => 'dashboard-account',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($user);
});

function dashboardImportPosition(string $batch, string $asOf, string $instrument, string $quantity, string $accountReference = 'dashboard-account'): void
{
    app(PortfolioImportService::class)->persist(new ImportBatch('xtb', $accountReference, $batch, new DateTimeImmutable($asOf)), [
        PortfolioImportRow::valid("{$batch}-{$instrument}", new CanonicalInstrument($instrument), $quantity, null, new DateTimeImmutable($asOf), ['instrument' => $instrument, 'quantity' => $quantity]),
    ]);
}

function dashboardPrice(string $instrument, string $date, string $currency, string $close): void
{
    $id = DB::table('market_data_snapshots')->insertGetId([
        'canonical_instrument' => $instrument,
        'provider' => 'dashboard-provider',
        'provider_symbol' => $instrument,
        'exchange' => 'XWAR',
        'quote_currency' => $currency,
        'session_date' => $date,
        'retrieved_at' => now(),
        'source_timezone' => 'Europe/Warsaw',
        'provider_version' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('daily_ohlc_observations')->insert([
        'market_data_snapshot_id' => $id,
        'trading_date' => $date,
        'open' => $close,
        'high' => $close,
        'low' => $close,
        'close' => $close,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function dashboardFx(string $currency, string $date, string $availability, ?string $rate, string $identity): void
{
    DB::table('fx_rate_snapshots')->insert([
        'provider_implementation_version' => 'nbp-dashboard-test',
        'currency' => $currency,
        'requested_date' => $date,
        'effective_date' => $availability === 'stale' ? '2026-01-01' : $date,
        'availability' => $availability,
        'pln_per_unit' => $rate,
        'reason' => $availability === 'stale' ? 'source_stale' : null,
        'attempts' => 1,
        'retrieved_at' => now(),
        'api_endpoint' => 'https://example.test/fx',
        'table' => 'A',
        'source_timezone' => 'Europe/Warsaw',
        'provider_response_metadata' => '{}',
        'source_observation_identity' => hash('sha256', $identity),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('renders an authenticated dashboard for an explicit valuation date', function (): void {
    dashboardImportPosition('dashboard-pzu', '2026-01-02T00:00:00+01:00', 'PZU.PL', '2');
    dashboardPrice('PZU.PL', '2026-01-02', 'PLN', '10');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/ValuationDashboard', false)
            ->where('valuationDate', '2026-01-02')
            ->where('totalPlnGrosze', '2000')
            ->where('state', 'ready')
            ->where('error', null)
            ->has('positions', 1)
            ->where('positions.0.instrument', 'PZU.PL')
            ->where('positions.0.quantity', '2')
            ->where('positions.0.sourcePrice.amount', '10')
            ->where('positions.0.sourcePrice.currency', 'PLN')
            ->where('positions.0.fx.status', 'not_required')
            ->where('positions.0.plnGrosze', 2000)
        );
});

it('excludes positions owned by another user account from the active portfolio valuation', function (): void {
    dashboardImportPosition('dashboard-owned', '2026-01-02T00:00:00+01:00', 'PZU.PL', '2');
    dashboardPrice('PZU.PL', '2026-01-02', 'PLN', '10');

    $otherUser = User::factory()->create();
    DB::table('portfolio_accounts')->insert([
        'user_id' => $otherUser->id,
        'broker' => 'xtb',
        'account_reference' => 'dashboard-other-user-account',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    dashboardImportPosition('dashboard-other-user', '2026-01-02T00:00:00+01:00', 'OTHER.PL', '9', 'dashboard-other-user-account');
    dashboardPrice('OTHER.PL', '2026-01-02', 'PLN', '10');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', '2000')
            ->has('positions', 1)
            ->where('positions.0.instrument', 'PZU.PL')
        );
});

it('renders an empty dashboard without inventing portfolio totals', function (): void {
    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/ValuationDashboard', false)
            ->where('valuationDate', '2026-01-02')
            ->where('totalPlnGrosze', '0')
            ->where('state', 'empty')
            ->has('positions', 0)
        );
});

it('keeps unavailable positions and stale FX diagnostics visible in deterministic order', function (): void {
    dashboardImportPosition('dashboard-zulu', '2026-01-02T00:00:00+01:00', 'ZULU.US', '3');
    dashboardImportPosition('dashboard-alpha', '2026-01-02T00:00:00+01:00', 'ALPHA.PL', '1');
    dashboardPrice('ZULU.US', '2026-01-02', 'USD', '10');
    dashboardFx('USD', '2026-01-02', 'stale', '4', 'dashboard-stale');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', '0')
            ->where('state', 'ready')
            ->has('positions', 2)
            ->where('positions.0.instrument', 'ALPHA.PL')
            ->where('positions.0.availability', 'unavailable')
            ->where('positions.0.sourcePrice.amount', null)
            ->where('positions.0.sourcePrice.diagnostic', 'market_price_missing_exact_date')
            ->where('positions.0.plnGrosze', null)
            ->where('positions.1.instrument', 'ZULU.US')
            ->where('positions.1.availability', 'unavailable')
            ->where('positions.1.fx.status', 'stale')
            ->where('positions.1.fx.diagnostic', 'source_stale')
            ->where('positions.1.diagnostics', ['fx_rate_stale_rejected:source_stale'])
            ->where('positions.1.plnGrosze', null)
        );
});

it('serializes large total PLN grosze as an exact decimal string', function (): void {
    dashboardImportPosition('dashboard-large-total', '2026-01-02T00:00:00+01:00', 'LARGE.PL', '1');
    dashboardPrice('LARGE.PL', '2026-01-02', 'PLN', '90071992547409.93');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', '9007199254740993')
            ->where('positions.0.plnGrosze', 9007199254740993)
        );
});

it('requires an explicit ISO valuation date', function (): void {
    $this->get('/portfolio/valuation')->assertInvalid(['date']);
    $this->get('/portfolio/valuation?date=02-01-2026')->assertInvalid(['date']);
});
