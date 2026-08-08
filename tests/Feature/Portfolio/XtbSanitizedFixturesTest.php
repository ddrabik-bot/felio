<?php

declare(strict_types=1);

use App\Application\Portfolio\Xtb\XtbXlsxParser;

it('creates independent temporary uploads from a sanitized fixture and removes them on cleanup', function (): void {
    $first = temporaryXtbUpload();
    $second = temporaryXtbUpload();

    $firstPath = $first->getRealPath();
    $secondPath = $second->getRealPath();

    expect($firstPath)->toBeString()
        ->and($secondPath)->toBeString()
        ->and($firstPath)->not->toBe($secondPath)
        ->and(file_get_contents($firstPath))->toBe(xtbFixtureContents())
        ->and(file_get_contents($secondPath))->toBe(xtbFixtureContents());

    cleanupTemporaryXtbUpload($first);
    cleanupTemporaryXtbUpload($second);

    expect(is_file($firstPath))->toBeFalse()
        ->and(is_file($secondPath))->toBeFalse();
});

it('keeps the representative sanitized workbook rows private and parseable', function (): void {
    $workbook = (new XtbXlsxParser)->parse(xtbFixturePath());

    expect($workbook->accountReference)->toBe('XTB-SYNTHETIC-001')
        ->and($workbook->rows)->toHaveCount(4)
        ->and($workbook->rows[0]->diagnostic)->toBeNull()
        ->and($workbook->rows[1]->diagnostic)->toBeNull()
        ->and($workbook->rows[2]->diagnostic)->toBe('unsupported_cash_operation')
        ->and($workbook->rows[3]->diagnostic)->toBe('unsupported_closed_position')
        ->and($workbook->rows[0]->symbol)->toBe('PZU')
        ->and($workbook->rows[0]->rawValues['comment'])->toBe('synthetic purchase');
});

it('provides a duplicate-row fixture without relying on private broker data', function (): void {
    $rows = (new XtbXlsxParser)->parse(xtbFixturePath('synthetic-xtb-duplicate-rows.xlsx'))->rows;

    expect($rows)->toHaveCount(5)
        ->and(array_map(static fn ($row): ?string => $row->symbol, $rows))->toBe(['PZU', 'PZU', 'PZU', 'PZU', 'PZU'])
        ->and($rows[3]->rawValues['comment'])->toBe('synthetic duplicate candidate');
});

it('provides an empty Closed Positions fixture with no position rows', function (): void {
    $rows = (new XtbXlsxParser)->parse(xtbFixturePath('synthetic-xtb-empty-closed-positions.xlsx'))->rows;

    expect($rows)->toHaveCount(3)
        ->and(array_filter($rows, static fn ($row): bool => $row->sheet === 'Closed Positions'))->toBe([]);
});

it('covers the Closed Positions contract scenarios with synthetic workbooks', function (string $fixture, string $symbol, ?string $closeTime, ?string $closePrice): void {
    $rows = (new XtbXlsxParser)->parse(xtbFixturePath($fixture))->rows;
    $closedRows = array_values(array_filter($rows, static fn ($row): bool => $row->sheet === 'Closed Positions'));

    expect($closedRows)->toHaveCount(1)
        ->and($closedRows[0]->symbol)->toBe($symbol)
        ->and($closedRows[0]->diagnostic)->toBe('unsupported_closed_position')
        ->and($closedRows[0]->rawValues['close_timestamp'] ?? null)->toBe($closeTime)
        ->and($closedRows[0]->rawValues['close_price'] ?? null)->toBe($closePrice);
})->with([
    ['synthetic-xtb-closed-positions-valid.xlsx', 'PZU', '46000.5', '65.00000000'],
    ['synthetic-xtb-closed-positions-rejected.xlsx', 'PZU', 'not-a-close-date', 'not-a-close-price'],
    ['synthetic-xtb-closed-positions-unknown-instrument.xlsx', 'SYNTH-UNKNOWN', '46000.5', '65.00000000'],
    ['synthetic-xtb-closed-positions-unresolved-reference.xlsx', 'SYNTH-UNRESOLVED', '46000.5', '65.00000000'],
]);
