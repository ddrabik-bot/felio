<?php

namespace App\Application\Portfolio\Xtb;

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class XtbPortfolioImportAdapter
{
    public function __construct(
        private XtbXlsxParser $parser,
        private PortfolioImportService $importService,
    ) {}

    /** @param array<string, CanonicalInstrument> $explicitInstrumentMappings */
    public function import(string $path, array $explicitInstrumentMappings, DateTimeImmutable $importedAt): XtbImportResult
    {
        foreach ($explicitInstrumentMappings as $symbol => $instrument) {
            if (! is_string($symbol) || ! $instrument instanceof CanonicalInstrument) {
                throw new InvalidArgumentException('XTB instrument mappings must map a source symbol to a CanonicalInstrument.');
            }
        }

        $workbook = $this->parser->parse($path);
        $rows = [];
        $valid = 0;
        $rejected = 0;
        foreach ($workbook->rows as $parsed) {
            $rawValues = ['sheet' => $parsed->sheet, ...$parsed->rawValues];
            $identity = PortfolioImportRow::deterministicIdentity($rawValues, $parsed->sourceRowReference);
            $instrument = $parsed->symbol === null ? null : ($explicitInstrumentMappings[$parsed->symbol] ?? null);
            if ($parsed->isValidCashTrade() && $instrument !== null) {
                $rows[] = PortfolioImportRow::valid($identity, $instrument, $parsed->quantity, null, $parsed->asOf, $rawValues);
                $valid++;

                continue;
            }

            $diagnostic = $parsed->diagnostic ?? 'canonical_instrument_unresolved';
            $rows[] = PortfolioImportRow::rejected($identity, $parsed->asOf, $rawValues, $diagnostic);
            $rejected++;
        }

        $batchIdentity = hash_file('sha256', $path);
        if ($batchIdentity === false) {
            throw new InvalidArgumentException('The XTB workbook cannot be hashed locally.');
        }
        $this->importService->persist(new ImportBatch('xtb', $workbook->accountReference, $batchIdentity, $importedAt), $rows);

        return new XtbImportResult($valid, $rejected);
    }
}
