<?php

namespace App\Infrastructure\Portfolio;

use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRepository;
use App\Domain\Portfolio\PortfolioImportRow;
use App\Domain\Portfolio\PortfolioPositionProjection;
use App\Domain\Portfolio\SourceRowStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;

final class EloquentPortfolioImportRepository implements PortfolioImportRepository
{
    /** @param list<PortfolioImportRow> $rows */
    public function persist(ImportBatch $batch, array $rows): void
    {
        $identities = array_map(static fn (PortfolioImportRow $row): string => $row->sourceRowIdentity, $rows);
        if (count($identities) !== count(array_unique($identities))) {
            throw new \InvalidArgumentException('A batch cannot contain duplicate source row identities.');
        }

        DB::transaction(function () use ($batch, $rows): void {
            $now = now();
            $accountId = $this->upsertAccount($batch, $now);
            $batchId = $this->upsertBatch($batch, $accountId, $now);

            foreach ($rows as $row) {
                $this->upsertSourceRow($batchId, $row, $now);
            }
            $this->rebuildPositions($accountId, $now);
        });
    }

    /** @param list<PortfolioImportRow> $rows */
    public function persistToAccount(ImportBatch $batch, array $rows, int $portfolioAccountId): void
    {
        $identities = array_map(static fn (PortfolioImportRow $row): string => $row->sourceRowIdentity, $rows);
        if (count($identities) !== count(array_unique($identities))) {
            throw new \InvalidArgumentException('A batch cannot contain duplicate source row identities.');
        }

        DB::transaction(function () use ($batch, $rows, $portfolioAccountId): void {
            $now = now();
            $accountExists = DB::table('portfolio_accounts')->where('id', $portfolioAccountId)->exists();
            if (! $accountExists) {
                throw new \InvalidArgumentException('Portfolio account not found.');
            }
            $batchId = $this->upsertBatch($batch, $portfolioAccountId, $now);

            foreach ($rows as $row) {
                $this->upsertSourceRow($batchId, $row, $now);
            }
            $this->rebuildPositions($portfolioAccountId, $now);
        });
    }

    private function upsertAccount(ImportBatch $batch, Carbon $now): int
    {
        DB::table('portfolio_accounts')->upsert([[
            'broker' => $batch->broker,
            'account_reference' => $batch->accountReference,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['broker', 'account_reference'], ['updated_at']);

        return (int) DB::table('portfolio_accounts')
            ->where('broker', $batch->broker)
            ->where('account_reference', $batch->accountReference)
            ->value('id');
    }

    private function upsertBatch(ImportBatch $batch, int $accountId, Carbon $now): int
    {
        DB::table('portfolio_import_batches')->upsert([[
            'portfolio_account_id' => $accountId,
            'source_batch_identity' => $batch->sourceBatchIdentity,
            'imported_at' => $batch->importedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['portfolio_account_id', 'source_batch_identity'], ['imported_at', 'updated_at']);

        return (int) DB::table('portfolio_import_batches')
            ->where('portfolio_account_id', $accountId)
            ->where('source_batch_identity', $batch->sourceBatchIdentity)
            ->value('id');
    }

    private function upsertSourceRow(int $batchId, PortfolioImportRow $row, Carbon $now): void
    {
        DB::table('portfolio_import_source_rows')->upsert([[
            'portfolio_import_batch_id' => $batchId,
            'source_row_identity' => $row->sourceRowIdentity,
            'status' => $row->status->value,
            'canonical_instrument' => $row->instrument?->value,
            'quantity' => $row->quantity,
            'average_cost_pln_grosze' => $row->averageCostPlnGrosze,
            'as_of' => $row->asOf,
            'raw_values' => $this->json($row->rawValues),
            'diagnostic' => $row->diagnostic,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['portfolio_import_batch_id', 'source_row_identity'], [
            'status', 'canonical_instrument', 'quantity', 'average_cost_pln_grosze', 'as_of', 'raw_values', 'diagnostic', 'updated_at',
        ]);
    }

    private function rebuildPositions(int $accountId, Carbon $now): void
    {
        DB::table('portfolio_accounts')->where('id', $accountId)->lockForUpdate()->first();
        $rows = DB::table('portfolio_import_source_rows as rows')
            ->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')
            ->where('batches.portfolio_account_id', $accountId)
            ->where('rows.status', SourceRowStatus::Valid->value)
            ->whereNotNull('rows.canonical_instrument')
            ->orderBy('rows.as_of')
            ->orderBy('batches.imported_at')
            ->orderBy('batches.id')
            ->orderBy('rows.id')
            ->select(['rows.id', 'rows.canonical_instrument', 'rows.quantity', 'rows.average_cost_pln_grosze', 'rows.as_of', 'rows.raw_values', 'batches.id as batch_id'])
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'canonicalInstrument' => $row->canonical_instrument,
                'quantity' => (string) $row->quantity,
                'averageCostPlnGrosze' => $row->average_cost_pln_grosze === null ? null : (int) $row->average_cost_pln_grosze,
                'asOf' => $row->as_of,
                'sourceImportBatchId' => (int) $row->batch_id,
                'rawValues' => json_decode($row->raw_values, true, 512, JSON_THROW_ON_ERROR),
            ]);
        $positions = PortfolioPositionProjection::rebuild($rows);

        DB::table('portfolio_positions')->where('portfolio_account_id', $accountId)->delete();
        if ($positions === []) {
            return;
        }

        DB::table('portfolio_positions')->insert(array_map(static fn (array $position): array => [
            'portfolio_account_id' => $accountId,
            'canonical_instrument' => $position['canonicalInstrument'],
            'quantity' => $position['quantity'],
            'average_cost_pln_grosze' => $position['averageCostPlnGrosze'],
            'as_of' => $position['asOf'],
            'source_import_batch_id' => $position['sourceImportBatchId'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $positions));
    }

    /** @param array<string, mixed> $values */
    private function json(array $values): string
    {
        try {
            return json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Raw source values must be JSON serializable.', previous: $exception);
        }
    }
}
