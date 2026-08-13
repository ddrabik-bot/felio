<?php

namespace App\Application\Portfolio\Xtb;

use App\Domain\MarketData\CanonicalInstrument;
use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioImportService;
use App\Domain\Portfolio\PortfolioPositionProjection;
use App\Domain\Portfolio\SourceRowStatus;
use App\Domain\Valuation\Decimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final readonly class XtbManualImportWorkflow
{
    public function __construct(
        private XtbPortfolioImportAdapter $adapter,
        private PortfolioImportService $importService,
    ) {}

    /** @param array<string, string> $mappings */
    public function analyze(string $path, array $mappings = []): XtbImportAnalysis
    {
        return $this->adapter->analyze($path, $this->canonicalMappings($mappings));
    }

    /** @param array<string, string> $mappings @return array{batchId: int, status: string, summary: array{valid: int, pending: int, rejected: int}} */
    public function confirm(string $path, array $mappings, int $portfolioAccountId): array
    {
        if (! $this->isGeneratedTemporaryWorkbookPath($path)) {
            throw new InvalidArgumentException('The temporary XTB import path is invalid.');
        }

        try {
            $analysis = $this->analyze(Storage::disk('local')->path($path), $mappings);
            $this->importService->persistToAccount(new ImportBatch('xtb', $analysis->accountReference, $analysis->batchIdentity, new DateTimeImmutable), $analysis->rows, $portfolioAccountId);
            $summary = $analysis->summary();
            $status = $summary['pending'] > 0 || $summary['rejected'] > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED';
            $batchId = (int) DB::table('portfolio_import_batches')
                ->where('portfolio_account_id', $portfolioAccountId)
                ->where('source_batch_identity', $analysis->batchIdentity)
                ->value('id');
            DB::table('portfolio_import_batches')->where('id', $batchId)->update(['status' => $status, 'updated_at' => now()]);

            return compact('batchId', 'status', 'summary');
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    /** @param array<string, string> $mappings @return array{status: string, summary: array{valid: int, pending: int, rejected: int}} */
    public function reprocess(int $batchId, array $mappings): array
    {
        $batch = DB::table('portfolio_import_batches')->where('id', $batchId)->first();
        if ($batch === null) {
            throw new InvalidArgumentException('Import batch not found.');
        }
        $canonicalMappings = $this->canonicalMappings($mappings);
        $rows = DB::table('portfolio_import_source_rows')->where('portfolio_import_batch_id', $batchId)->orderBy('id')->get();
        $imports = [];
        foreach ($rows as $row) {
            $raw = json_decode($row->raw_values, true, 512, JSON_THROW_ON_ERROR);
            $status = SourceRowStatus::from($row->status);
            if ($status === SourceRowStatus::Pending && isset($canonicalMappings[$raw['xtb_symbol'] ?? ''])) {
                $cost = isset($raw['xtb_price']) && is_string($raw['xtb_price'])
                    ? Decimal::plnToGrosze(Decimal::roundPlnGrosz($raw['xtb_price']))
                    : null;
                $imports[] = PortfolioImportRow::valid($row->source_row_identity, $canonicalMappings[$raw['xtb_symbol']], $raw['xtb_quantity'], $cost, new DateTimeImmutable($row->as_of), $raw);
            } elseif ($status === SourceRowStatus::Valid) {
                $imports[] = PortfolioImportRow::valid($row->source_row_identity, new CanonicalInstrument($row->canonical_instrument), $row->quantity, $row->average_cost_pln_grosze, new DateTimeImmutable($row->as_of), $raw);
            } elseif ($status === SourceRowStatus::Pending) {
                $imports[] = PortfolioImportRow::pending($row->source_row_identity, new DateTimeImmutable($row->as_of), $raw, $row->diagnostic);
            } else {
                $imports[] = PortfolioImportRow::rejected($row->source_row_identity, new DateTimeImmutable($row->as_of), $raw, $row->diagnostic);
            }
        }
        $this->importService->persistToAccount(new ImportBatch('xtb', $this->accountReference((int) $batch->portfolio_account_id), $batch->source_batch_identity, new DateTimeImmutable($batch->imported_at)), $imports, (int) $batch->portfolio_account_id);
        $summary = (new XtbImportAnalysis('', $batch->source_batch_identity, $imports))->summary();
        $status = $summary['pending'] > 0 || $summary['rejected'] > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED';
        DB::table('portfolio_import_batches')->where('id', $batchId)->update(['status' => $status, 'updated_at' => now()]);

        return compact('status', 'summary');
    }

    public function delete(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = DB::table('portfolio_import_batches')->where('id', $batchId)->first();
            if ($batch === null) {
                throw new InvalidArgumentException('Import batch not found.');
            }
            DB::table('portfolio_accounts')->where('id', $batch->portfolio_account_id)->lockForUpdate()->firstOrFail();
            $batch = DB::table('portfolio_import_batches')->where('id', $batchId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new InvalidArgumentException('Import batch not found.');
            }
            $boundary = DB::table('portfolio_import_source_rows')->where('portfolio_import_batch_id', $batchId)->min('as_of') ?? $batch->imported_at;
            DB::table('portfolio_import_recalculation_boundaries')->insert([
                'deleted_import_batch_id' => $batchId,
                'portfolio_account_id' => $batch->portfolio_account_id,
                'boundary_as_of' => $boundary,
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('portfolio_positions')->where('portfolio_account_id', $batch->portfolio_account_id)->delete();
            DB::table('portfolio_import_batches')->where('id', $batchId)->delete();
            $rows = DB::table('portfolio_import_source_rows as rows')->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')->where('batches.portfolio_account_id', $batch->portfolio_account_id)->where('rows.status', SourceRowStatus::Valid->value)->orderBy('rows.as_of')->orderBy('batches.imported_at')->orderBy('batches.id')->orderBy('rows.id')->select(['rows.id', 'rows.canonical_instrument', 'rows.quantity', 'rows.average_cost_pln_grosze', 'rows.as_of', 'rows.raw_values', 'batches.id as batch_id'])->get();
            $positions = PortfolioPositionProjection::rebuild($rows->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'canonicalInstrument' => $row->canonical_instrument,
                'quantity' => (string) $row->quantity,
                'averageCostPlnGrosze' => $row->average_cost_pln_grosze === null ? null : (int) $row->average_cost_pln_grosze,
                'asOf' => $row->as_of,
                'sourceImportBatchId' => (int) $row->batch_id,
                'rawValues' => json_decode($row->raw_values, true, 512, JSON_THROW_ON_ERROR),
            ]));
            if ($positions !== []) {
                DB::table('portfolio_positions')->insert(array_map(static fn (array $position): array => [
                    'portfolio_account_id' => $batch->portfolio_account_id,
                    'canonical_instrument' => $position['canonicalInstrument'],
                    'quantity' => $position['quantity'],
                    'average_cost_pln_grosze' => $position['averageCostPlnGrosze'],
                    'as_of' => $position['asOf'],
                    'source_import_batch_id' => $position['sourceImportBatchId'],
                    'created_at' => now(), 'updated_at' => now(),
                ], $positions));
            }
        });
    }

    private function isGeneratedTemporaryWorkbookPath(string $path): bool
    {
        return preg_match(
            '/\\Axtb-imports\\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\.xlsx\\z/iD',
            $path,
        ) === 1;
    }

    /** @param array<string, string> $mappings @return array<string, CanonicalInstrument> */
    private function canonicalMappings(array $mappings): array
    {
        $result = [];
        foreach ($mappings as $symbol => $instrument) {
            if (! is_string($symbol) || trim($symbol) === '' || ! is_string($instrument) || trim($instrument) === '') {
                throw new InvalidArgumentException('XTB mappings require non-empty source and canonical instrument values.');
            }
            $result[$symbol] = new CanonicalInstrument($instrument);
        }

        return $result;
    }

    private function accountReference(int $accountId): string
    {
        return (string) DB::table('portfolio_accounts')->where('id', $accountId)->value('account_reference');
    }
}
