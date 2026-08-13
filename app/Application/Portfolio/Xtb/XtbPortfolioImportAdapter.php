<?php

namespace App\Application\Portfolio\Xtb;

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\Portfolio\SourceRowStatus;
use App\Domain\Valuation\Decimal;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class XtbPortfolioImportAdapter
{
    public function __construct(
        private XtbXlsxParser $parser,
        private PortfolioImportService $importService,
    ) {}

    /** @param array<string, CanonicalInstrument> $explicitInstrumentMappings */
    public function analyze(string $path, array $explicitInstrumentMappings): XtbImportAnalysis
    {
        foreach ($explicitInstrumentMappings as $symbol => $instrument) {
            if (! is_string($symbol) || ! $instrument instanceof CanonicalInstrument) {
                throw new InvalidArgumentException('XTB instrument mappings must map a source symbol to a CanonicalInstrument.');
            }
        }

        $workbook = $this->parser->parse($path);
        $rows = [];
        foreach ($workbook->rows as $parsed) {
            $rawValues = [
                'sheet' => $parsed->sheet,
                'source_row_reference' => $parsed->sourceRowReference,
                'xtb_symbol' => $parsed->symbol,
                'xtb_operation' => $parsed->operation,
                'xtb_quantity' => $parsed->quantity,
                'xtb_price' => $parsed->price,
                ...$parsed->rawValues,
            ];
            $identity = PortfolioImportRow::deterministicIdentity($rawValues, $parsed->sourceRowReference);
            $instrument = $parsed->symbol === null ? null : ($explicitInstrumentMappings[$parsed->symbol] ?? null);
            if ($parsed->isValidCashTrade() && $instrument !== null) {
                $cost = Decimal::plnToGrosze(Decimal::roundPlnGrosz((string) $parsed->price));
                $rows[] = PortfolioImportRow::valid($identity, $instrument, $parsed->quantity, $cost, $parsed->asOf, $rawValues);

                continue;
            }
            if ($parsed->isValidCashTrade()) {
                $rows[] = PortfolioImportRow::pending($identity, $parsed->asOf, $rawValues, 'canonical_instrument_unresolved');

                continue;
            }
            $rows[] = PortfolioImportRow::rejected($identity, $parsed->asOf, $rawValues, $parsed->diagnostic ?? 'canonical_instrument_unresolved');
        }

        $batchIdentity = hash_file('sha256', $path);
        if ($batchIdentity === false) {
            throw new InvalidArgumentException('The XTB workbook cannot be hashed locally.');
        }

        return new XtbImportAnalysis($workbook->accountReference, $batchIdentity, $rows);
    }

    /** @param array<string, CanonicalInstrument> $explicitInstrumentMappings */
    public function import(string $path, array $explicitInstrumentMappings, DateTimeImmutable $importedAt): XtbImportResult
    {
        $analysis = $this->analyze($path, $explicitInstrumentMappings);
        $legacyRows = array_map(static function (PortfolioImportRow $row): PortfolioImportRow {
            return $row->status === SourceRowStatus::Pending
                ? PortfolioImportRow::rejected($row->sourceRowIdentity, $row->asOf, $row->rawValues, $row->diagnostic ?? 'canonical_instrument_unresolved')
                : $row;
        }, $analysis->rows);
        $this->importService->persist(new ImportBatch('xtb', $analysis->accountReference, $analysis->batchIdentity, $importedAt), $legacyRows);
        $summary = (new XtbImportAnalysis($analysis->accountReference, $analysis->batchIdentity, $legacyRows))->summary();

        return new XtbImportResult($summary['valid'], $summary['rejected']);
    }
}
