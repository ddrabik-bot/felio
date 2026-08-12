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

function dashboardImportPosition(string $batch, string $asOf, string $instrument, string $quantity, ?int $averageCostPlnGrosze = null, string $accountReference = 'dashboard-account'): void
{
    app(PortfolioImportService::class)->persist(new ImportBatch('xtb', $accountReference, $batch, new DateTimeImmutable($asOf)), [
        PortfolioImportRow::valid("{$batch}-{$instrument}", new CanonicalInstrument($instrument), $quantity, $averageCostPlnGrosze, new DateTimeImmutable($asOf), ['instrument' => $instrument, 'quantity' => $quantity]),
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
    dashboardImportPosition('dashboard-other-user', '2026-01-02T00:00:00+01:00', 'OTHER.PL', '9', null, 'dashboard-other-user-account');
    dashboardPrice('OTHER.PL', '2026-01-02', 'PLN', '10');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', '2000')
            ->has('positions', 1)
            ->where('positions.0.instrument', 'PZU.PL')
        );
});

it('renders an empty dashboard with an explicit unavailable total', function (): void {
    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/ValuationDashboard', false)
            ->where('valuationDate', '2026-01-02')
            ->where('totalPlnGrosze', null)
            ->where('valuation.availability', 'unavailable')
            ->where('valuation.diagnostics', ['no_confirmed_positions'])
            ->where('state', 'empty')
            ->has('positions', 0)
        );
});

it('keeps unavailable positions and stale FX diagnostics visible without fabricating a total', function (): void {
    dashboardImportPosition('dashboard-zulu', '2026-01-02T00:00:00+01:00', 'ZULU.US', '3');
    dashboardImportPosition('dashboard-alpha', '2026-01-02T00:00:00+01:00', 'ALPHA.PL', '1');
    dashboardPrice('ZULU.US', '2026-01-02', 'USD', '10');
    dashboardFx('USD', '2026-01-02', 'stale', '4', 'dashboard-stale');

    $this->get('/portfolio/valuation?date=2026-01-02')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', null)
            ->where('valuation.availability', 'unavailable')
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

it('uses one computed read model for selected-date totals, KPIs, holdings, and allocation', function (): void {
    dashboardImportPosition('dashboard-history-pzu', '2026-01-01T00:00:00+01:00', 'PZU.PL', '2', 1000);
    dashboardPrice('PZU.PL', '2026-01-03', 'PLN', '15');
    $accountId = DB::table('portfolio_accounts')->where('account_reference', 'dashboard-account')->value('id');
    $otherUser = User::factory()->create();
    $otherAccountId = DB::table('portfolio_accounts')->insertGetId([
        'user_id' => $otherUser->id,
        'broker' => 'xtb',
        'account_reference' => 'dashboard-history-other',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['2026-01-01', 2000], ['2026-01-02', 2500], ['2026-01-03', 2600]] as [$date, $total]) {
        DB::table('portfolio_value_snapshots')->insert([
            'portfolio_account_id' => $accountId,
            'valuation_date' => $date,
            'availability' => 'available',
            'total_pln_grosze' => $total,
            'source' => 'fixture',
            'diagnostics' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::table('portfolio_value_snapshots')->insert([
        'portfolio_account_id' => $otherAccountId,
        'valuation_date' => '2026-01-02',
        'availability' => 'available',
        'total_pln_grosze' => 999999,
        'source' => 'fixture',
        'diagnostics' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get('/portfolio/valuation?date=2026-01-03&range=1M')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalPlnGrosze', '3000')
            ->where('range', '1M')
            ->has('history', 3)
            ->where('history.0.date', '2026-01-01')
            ->where('history.0.totalPlnGrosze', '2000')
            ->where('history.1.date', '2026-01-02')
            ->where('history.1.totalPlnGrosze', '2500')
            ->where('history.2.date', '2026-01-03')
            ->where('history.2.totalPlnGrosze', '3000')
            ->where('history.2.source', 'computed_read_model')
            ->where('kpis.totalValuePlnGrosze', '3000')
            ->where('kpis.investedCapitalPlnGrosze', '1000')
            ->where('kpis.investedCapitalAvailability', 'available')
            ->where('kpis.profitLossPlnGrosze', '2000')
            ->where('kpis.profitLossAvailability', 'available')
            ->where('kpis.periodReturnBps', '5000')
            ->where('kpis.periodReturnAvailability', 'available')
            ->where('allocation.byHolding.0.instrument', 'PZU.PL')
            ->where('allocation.byHolding.0.valuePlnGrosze', '3000')
            ->where('allocation.byHolding.0.weightBps', '10000')
            ->where('allocation.byAssetClass.0.assetClass', 'Equity')
            ->where('allocation.byAssetClass.0.valuePlnGrosze', '3000')
            ->where('allocation.byAssetClass.0.weightBps', '10000')
        );
});

it('groups holdings into typed asset classes and normalizes every allocation to exactly 100 percent', function (): void {
    dashboardImportPosition('dashboard-allocation-equity', '2026-01-03T00:00:00+01:00', 'PZU.PL', '2');
    dashboardImportPosition('dashboard-allocation-unclassified', '2026-01-03T00:00:00+01:00', 'GOLD.DE', '1');
    dashboardPrice('PZU.PL', '2026-01-03', 'PLN', '10');
    dashboardPrice('GOLD.DE', '2026-01-03', 'PLN', '10');

    $this->get('/portfolio/valuation?date=2026-01-03&range=1D')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('allocation.availability', 'available')
            ->has('allocation.byHolding', 2)
            ->where('allocation.byHolding.0.instrument', 'GOLD.DE')
            ->where('allocation.byHolding.0.weightBps', '3333')
            ->where('allocation.byHolding.1.instrument', 'PZU.PL')
            ->where('allocation.byHolding.1.weightBps', '6667')
            ->has('allocation.byAssetClass', 2)
            ->where('allocation.byAssetClass.0.assetClass', 'Equity')
            ->where('allocation.byAssetClass.0.weightBps', '6667')
            ->where('allocation.byAssetClass.1.assetClass', 'Unclassified')
            ->where('allocation.byAssetClass.1.weightBps', '3333')
        );
});

it('uses the canonical asset-class registry rather than an exchange suffix heuristic', function (): void {
    dashboardImportPosition('dashboard-class-equity', '2026-01-03T00:00:00+01:00', 'PZU.PL', '1');
    dashboardImportPosition('dashboard-class-etf', '2026-01-03T00:00:00+01:00', 'ETF.PL', '1');
    dashboardImportPosition('dashboard-class-bond', '2026-01-03T00:00:00+01:00', 'BOND.US', '1');
    dashboardPrice('PZU.PL', '2026-01-03', 'PLN', '10');
    dashboardPrice('ETF.PL', '2026-01-03', 'PLN', '20');
    dashboardPrice('BOND.US', '2026-01-03', 'PLN', '30');

    $this->get('/portfolio/valuation?date=2026-01-03&range=1D')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('allocation.byAssetClass.0.assetClass', 'Equity')
            ->where('allocation.byAssetClass.0.valuePlnGrosze', '1000')
            ->where('allocation.byAssetClass.1.assetClass', 'Exchange-traded fund')
            ->where('allocation.byAssetClass.1.valuePlnGrosze', '2000')
            ->where('allocation.byAssetClass.2.assetClass', 'Fixed income')
            ->where('allocation.byAssetClass.2.valuePlnGrosze', '3000')
        );
});

it('marks KPI and allocation unavailable when no confirmed position can be valued', function (): void {
    dashboardImportPosition('dashboard-unavailable', '2026-01-03T00:00:00+01:00', 'MISSING.PL', '1');

    $this->get('/portfolio/valuation?date=2026-01-03&range=1D')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('kpis.periodReturnBps', null)
            ->where('kpis.periodReturnAvailability', 'unavailable')
            ->where('allocation.availability', 'unavailable')
            ->has('history', 1)
            ->where('history.0.availability', 'unavailable')
            ->where('history.0.totalPlnGrosze', null)
        );
});

it('requires an explicit ISO valuation date', function (): void {
    $this->get('/portfolio/valuation')->assertInvalid(['date']);
    $this->get('/portfolio/valuation?date=02-01-2026')->assertInvalid(['date']);
});
