<?php

use App\Application\Portfolio\Xtb\XtbParsedWorkbook;
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

it('derives a unique product from Cash Operations transaction rows when top metadata is blank', function (): void {
    $path = sanitizedXtbWorkbookWithCashProducts(['STOCK', 'STOCK']);

    try {
        $result = (new XtbXlsxParser)->parse($path);

        expect($result->product)->toBe('STOCK');
    } finally {
        @unlink($path);
    }
});

it('rejects a missing product when neither top metadata nor Cash Operations rows provide one', function (): void {
    $path = sanitizedXtbWorkbookWithCashProducts(['', '']);

    try {
        expect(fn (): XtbParsedWorkbook => (new XtbXlsxParser)->parse($path))
            ->toThrow(InvalidArgumentException::class, 'missing account or product metadata');
    } finally {
        @unlink($path);
    }
});

it('rejects ambiguous products in Cash Operations transaction rows when top metadata is blank', function (): void {
    $path = sanitizedXtbWorkbookWithCashProducts(['STOCK', 'ETF']);

    try {
        expect(fn (): XtbParsedWorkbook => (new XtbXlsxParser)->parse($path))
            ->toThrow(InvalidArgumentException::class, 'missing account or product metadata');
    } finally {
        @unlink($path);
    }
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

/** @param list<string> $products */
function sanitizedXtbWorkbookWithCashProducts(array $products): string
{
    $path = tempnam(sys_get_temp_dir(), 'xtb-product-fallback-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a sanitized XTB workbook path.');
    }

    $archive = new ZipArchive;
    if ($archive->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create a sanitized XTB workbook archive.');
    }

    try {
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cash Operations" sheetId="1" r:id="rId1"/><sheet name="Closed Positions" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        $archive->addFromString('xl/worksheets/sheet1.xml', cashOperationsSheet($products));
        $archive->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Position</t></is></c><c r="B1" t="inlineStr"><is><t>Symbol</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="B2" t="inlineStr"><is><t>SANITIZED</t></is></c></row></sheetData></worksheet>');
    } finally {
        $archive->close();
    }

    return $path;
}

/** @param list<string> $products */
function cashOperationsSheet(array $products): string
{
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>Account</t></is></c><c r="B1" t="inlineStr"><is><t>XTB-SANITIZED</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Product</t></is></c></row>'
        .'<row r="5"><c r="A5" t="inlineStr"><is><t>Time</t></is></c><c r="B5" t="inlineStr"><is><t>Operation</t></is></c><c r="C5" t="inlineStr"><is><t>Symbol</t></is></c><c r="D5" t="inlineStr"><is><t>Volume</t></is></c><c r="E5" t="inlineStr"><is><t>Price</t></is></c><c r="F5" t="inlineStr"><is><t>Product</t></is></c></row>';

    foreach ($products as $index => $product) {
        $row = $index + 6;
        $escapedProduct = htmlspecialchars($product, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rows .= '<row r="'.$row.'"><c r="A'.$row.'"><v>46000.5</v></c><c r="B'.$row.'" t="inlineStr"><is><t>BUY</t></is></c><c r="C'.$row.'" t="inlineStr"><is><t>SANITIZED</t></is></c><c r="D'.$row.'"><v>1</v></c><c r="E'.$row.'"><v>10</v></c><c r="F'.$row.'" t="inlineStr"><is><t>'.$escapedProduct.'</t></is></c></row>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
}
