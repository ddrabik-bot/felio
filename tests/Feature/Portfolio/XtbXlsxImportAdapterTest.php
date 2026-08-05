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

it('ignores blank Cash Operations product cells while deriving the unique product', function (): void {
    $path = sanitizedXtbWorkbookWithCashProducts(['', 'STOCK']);

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

it('maps only case-sensitive canonical Stock purchase and Stock sale cash operations', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([
        ['operation' => 'Stock purchase', 'volume' => '2.00000000', 'price' => '61.72500000'],
        ['operation' => 'Stock sale', 'volume' => '3.00000000', 'price' => '62.72500000'],
        ['operation' => 'stock purchase', 'volume' => '4.00000000', 'price' => '63.72500000'],
        ['operation' => 'Stock Purchase', 'volume' => '5.00000000', 'price' => '64.72500000'],
        ['operation' => 'stock sale', 'volume' => '6.00000000', 'price' => '65.72500000'],
        ['operation' => 'Stock Sale', 'volume' => '7.00000000', 'price' => '66.72500000'],
        ['operation' => 'BUY', 'volume' => '8.00000000', 'price' => '67.72500000'],
        ['operation' => ' Stock purchase', 'volume' => '9.00000000', 'price' => '68.72500000'],
        ['operation' => "Stock purchase\t", 'volume' => '10.00000000', 'price' => '69.72500000'],
        ['operation' => "\nStock purchase", 'volume' => '11.00000000', 'price' => '70.72500000'],
        ['operation' => 'Stock sale ', 'volume' => '12.00000000', 'price' => '71.72500000'],
        ['operation' => "\tStock sale", 'volume' => '13.00000000', 'price' => '72.72500000'],
        ['operation' => "Stock sale\n", 'volume' => '14.00000000', 'price' => '73.72500000'],
    ]);

    try {
        $rows = (new XtbXlsxParser)->parse($path)->rows;

        expect($rows[0]->operation)->toBe('buy')
            ->and($rows[0]->diagnostic)->toBeNull()
            ->and($rows[1]->operation)->toBe('sell')
            ->and($rows[1]->diagnostic)->toBeNull();

        $nonCanonicalCashRows = array_filter(
            array_slice($rows, 2),
            static fn ($row): bool => $row->sheet === 'Cash Operations',
        );

        expect($nonCanonicalCashRows)->toHaveCount(11);

        foreach ($nonCanonicalCashRows as $row) {
            expect($row->operation)->toBeNull()
                ->and($row->diagnostic)->toBe('unsupported_cash_operation');
        }
    } finally {
        @unlink($path);
    }
});

it('extracts exact positive quantity and price strings from the canonical labeled cash comment', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([[
        'comment' => 'Quantity: 12345678901234567890.00000000000000000001; Price: 61.72500000',
    ]]);

    try {
        $row = (new XtbXlsxParser)->parse($path)->rows[0];

        expect($row->quantity)->toBe('12345678901234567890.00000000000000000001')
            ->and($row->price)->toBe('61.72500000')
            ->and($row->diagnostic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('extracts exact positive quantity and price strings from the canonical stock cash comment', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([[
        'comment' => 'STOCK SELL 0.000000000000000000001 @ 61.72500000',
    ]]);

    try {
        $row = (new XtbXlsxParser)->parse($path)->rows[0];

        expect($row->quantity)->toBe('0.000000000000000000001')
            ->and($row->price)->toBe('61.72500000')
            ->and($row->diagnostic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('preserves positive comment decimals below the BCMath legacy comparison scale', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([[
        'comment' => 'Quantity: 0.000000000000000000001; Price: 0.000000000000000000002',
    ]]);

    try {
        $row = (new XtbXlsxParser)->parse($path)->rows[0];

        expect($row->quantity)->toBe('0.000000000000000000001')
            ->and($row->price)->toBe('0.000000000000000000002')
            ->and($row->diagnostic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('uses a canonical labeled comment only for missing direct cash fields', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([
        [
            'volume' => '7.00000000',
            'comment' => 'Quantity: 2.00000000; Price: 61.72500000',
        ],
        [
            'price' => '9.50000000',
            'comment' => 'Quantity: 3.00000000; Price: 10.00000000',
        ],
    ]);

    try {
        $rows = (new XtbXlsxParser)->parse($path)->rows;

        expect($rows[0]->quantity)->toBe('7.00000000')
            ->and($rows[0]->price)->toBe('61.72500000')
            ->and($rows[0]->diagnostic)->toBeNull()
            ->and($rows[1]->quantity)->toBe('3.00000000')
            ->and($rows[1]->price)->toBe('9.50000000')
            ->and($rows[1]->diagnostic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('accepts complete direct cash fields without interpreting an unsupported comment', function (): void {
    $path = sanitizedXtbWorkbookWithCashRows([[
        'volume' => '2.00000000',
        'price' => '61.72500000',
        'comment' => 'Unstructured broker note',
    ]]);

    try {
        $row = (new XtbXlsxParser)->parse($path)->rows[0];

        expect($row->quantity)->toBe('2.00000000')
            ->and($row->price)->toBe('61.72500000')
            ->and($row->diagnostic)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('rejects non-canonical labeled cash comments when a direct cash field is missing', function (string $comment): void {
    $path = sanitizedXtbWorkbookWithCashRows([['comment' => $comment]]);

    try {
        $row = (new XtbXlsxParser)->parse($path)->rows[0];

        expect($row->quantity)->toBeNull()
            ->and($row->price)->toBeNull()
            ->and($row->diagnostic)->toBe('invalid_or_missing_labeled_trade_comment');
    } finally {
        @unlink($path);
    }
})->with([
    'zero quantity' => 'Quantity: 0; Price: 61.72500000',
    'zero price' => 'Quantity: 2.00000000; Price: 0',
    'negative quantity' => 'Quantity: -2.00000000; Price: 61.72500000',
    'negative price' => 'Quantity: 2.00000000; Price: -61.72500000',
    'negative stock quantity' => 'STOCK BUY -2.00000000 @ 61.72500000',
    'negative stock price' => 'STOCK SELL 2.00000000 @ -61.72500000',
    'stock equals delimiter' => 'STOCK BUY 2.00000000 = 61.72500000',
    'stock extra whitespace' => 'STOCK BUY  2.00000000 @ 61.72500000',
    'stock trailing text' => 'STOCK BUY 2.00000000 @ 61.72500000 settled',
    'stock unsupported operation' => 'STOCK HOLD 2.00000000 @ 61.72500000',
    'leading-zero quantity' => 'Quantity: 02; Price: 61.72500000',
    'comma decimal separator' => 'Quantity: 2,00000000; Price: 61.72500000',
    'unexpected suffix' => 'Quantity: 2.00000000; Price: 61.72500000 settled',
    'duplicate label' => 'Quantity: 2.00000000; Price: 61.72500000; Quantity: 3.00000000',
    'unrelated two-word prefix' => 'Trade confirmation Quantity: 2.00000000; Price: 61.72500000',
    'extra separator whitespace' => 'Quantity: 2.00000000;  Price: 61.72500000',
    'space before label separator' => 'Quantity : 2.00000000; Price: 61.72500000',
    'tab separator whitespace' => "Quantity: 2.00000000;\tPrice: 61.72500000",
    'labeled leading ASCII space' => ' Quantity: 2.00000000; Price: 61.72500000',
    'labeled trailing ASCII space' => 'Quantity: 2.00000000; Price: 61.72500000 ',
    'labeled leading tab' => "\tQuantity: 2.00000000; Price: 61.72500000",
    'labeled trailing tab' => "Quantity: 2.00000000; Price: 61.72500000\t",
    'stock leading newline' => "\nSTOCK BUY 2.00000000 @ 61.72500000",
    'stock trailing newline' => "STOCK BUY 2.00000000 @ 61.72500000\n",
]);

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
    return sanitizedXtbWorkbookWithCashRows(array_map(
        static fn (string $product): array => ['product' => $product],
        $products,
    ));
}

/** @param list<array{comment?: string, operation?: string, price?: string, product?: string, volume?: string}> $cashRows */
function sanitizedXtbWorkbookWithCashRows(array $cashRows): string
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
        $archive->addFromString('xl/worksheets/sheet1.xml', cashOperationsSheet($cashRows));
        $archive->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Position</t></is></c><c r="B1" t="inlineStr"><is><t>Symbol</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="B2" t="inlineStr"><is><t>SANITIZED</t></is></c></row></sheetData></worksheet>');
    } finally {
        $archive->close();
    }

    return $path;
}

/** @param list<array{comment?: string, operation?: string, price?: string, product?: string, volume?: string}> $cashRows */
function cashOperationsSheet(array $cashRows): string
{
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>Account</t></is></c><c r="B1" t="inlineStr"><is><t>XTB-SANITIZED</t></is></c></row>'
        .'<row r="2"><c r="A2" t="inlineStr"><is><t>Product</t></is></c></row>'
        .'<row r="5"><c r="A5" t="inlineStr"><is><t>Time</t></is></c><c r="B5" t="inlineStr"><is><t>Operation</t></is></c><c r="C5" t="inlineStr"><is><t>Symbol</t></is></c><c r="D5" t="inlineStr"><is><t>Volume</t></is></c><c r="E5" t="inlineStr"><is><t>Price</t></is></c><c r="F5" t="inlineStr"><is><t>Product</t></is></c><c r="G5" t="inlineStr"><is><t>Comment</t></is></c></row>';

    foreach ($cashRows as $index => $cashRow) {
        $row = $index + 6;
        $operation = htmlspecialchars($cashRow['operation'] ?? 'Stock purchase', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $volume = htmlspecialchars($cashRow['volume'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $price = htmlspecialchars($cashRow['price'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $product = htmlspecialchars($cashRow['product'] ?? 'STOCK', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $comment = htmlspecialchars($cashRow['comment'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $rows .= '<row r="'.$row.'"><c r="A'.$row.'"><v>46000.5</v></c><c r="B'.$row.'" t="inlineStr"><is><t>'.$operation.'</t></is></c><c r="C'.$row.'" t="inlineStr"><is><t>SANITIZED</t></is></c><c r="D'.$row.'" t="inlineStr"><is><t>'.$volume.'</t></is></c><c r="E'.$row.'" t="inlineStr"><is><t>'.$price.'</t></is></c><c r="F'.$row.'" t="inlineStr"><is><t>'.$product.'</t></is></c><c r="G'.$row.'" t="inlineStr"><is><t>'.$comment.'</t></is></c></row>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>';
}
