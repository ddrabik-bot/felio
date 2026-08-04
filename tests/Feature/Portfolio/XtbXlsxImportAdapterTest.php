<?php

use App\Application\Portfolio\Xtb\XtbPortfolioImportAdapter;
use App\Application\Portfolio\Xtb\XtbXlsxParser;
use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\PortfolioImportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

it('parses a sanitized XTB cash purchase into an exact decimal import candidate', function (): void {
    $result = (new XtbXlsxParser)->parse(base_path('tests/Fixtures/Xtb/synthetic-xtb-statement.xlsx'));

    expect($result->accountReference)->toBe('XTB-SYNTHETIC-001')
        ->and($result->product)->toBe('STOCK')
        ->and($result->rows)->toHaveCount(4)
        ->and($result->rows[0]->operation)->toBe('buy')
        ->and($result->rows[0]->quantity)->toBe('2.00000000')
        ->and($result->rows[0]->price)->toBe('61.72500000')
        ->and($result->rows[0]->asOf->format('Y-m-d\TH:i:sP'))->toBe('2025-12-09T12:00:00+00:00');
});

it('persists explicit cash-trade mappings idempotently while isolating rejected rows', function (): void {
    $adapter = new XtbPortfolioImportAdapter(new XtbXlsxParser, app(PortfolioImportService::class));
    $path = base_path('tests/Fixtures/Xtb/synthetic-xtb-statement.xlsx');

    $first = $adapter->import($path, ['PZU' => new CanonicalInstrument('PZU.PL')], new DateTimeImmutable('2025-12-12T12:00:00+00:00'));
    $second = $adapter->import($path, ['PZU' => new CanonicalInstrument('PZU.PL')], new DateTimeImmutable('2025-12-12T12:00:00+00:00'));

    expect($first->validRows)->toBe(2)
        ->and($first->rejectedRows)->toBe(2)
        ->and($second)->toEqual($first)
        ->and(DB::table('portfolio_accounts')->count())->toBe(1)
        ->and(DB::table('portfolio_import_batches')->count())->toBe(1)
        ->and(DB::table('portfolio_import_source_rows')->count())->toBe(4)
        ->and(DB::table('portfolio_import_source_rows')->where('status', 'rejected')->count())->toBe(2)
        ->and(DB::table('portfolio_positions')->count())->toBe(1)
        ->and(DB::table('portfolio_positions')->value('canonical_instrument'))->toBe('PZU.PL');
});

it('rejects otherwise valid cash trades without an explicit canonical mapping', function (): void {
    $result = (new XtbPortfolioImportAdapter(new XtbXlsxParser, app(PortfolioImportService::class)))
        ->import(base_path('tests/Fixtures/Xtb/synthetic-xtb-statement.xlsx'), [], new DateTimeImmutable('2025-12-12T12:00:00+00:00'));

    expect($result->validRows)->toBe(0)
        ->and($result->rejectedRows)->toBe(4)
        ->and(DB::table('portfolio_positions')->count())->toBe(0)
        ->and(DB::table('portfolio_import_source_rows')->where('diagnostic', 'canonical_instrument_unresolved')->count())->toBe(2);
});
