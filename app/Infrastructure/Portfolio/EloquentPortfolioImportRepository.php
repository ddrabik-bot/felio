<?php

namespace App\Infrastructure\Portfolio;

use App\Domain\Portfolio\ImportBatch;
use App\Domain\Portfolio\PortfolioImportRepository;
use App\Domain\Portfolio\PortfolioImportRow;
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

                if ($row->status === SourceRowStatus::Valid) {
                    $this->upsertPosition($accountId, $batchId, $row, $now);
                }
            }
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

    private function upsertPosition(int $accountId, int $batchId, PortfolioImportRow $row, Carbon $now): void
    {
        DB::table('portfolio_positions')->upsert([[
            'portfolio_account_id' => $accountId,
            'canonical_instrument' => $row->instrument?->value,
            'quantity' => $row->quantity,
            'average_cost_pln_grosze' => $row->averageCostPlnGrosze,
            'as_of' => $row->asOf,
            'source_import_batch_id' => $batchId,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['portfolio_account_id', 'canonical_instrument'], [
            'quantity', 'average_cost_pln_grosze', 'as_of', 'source_import_batch_id', 'updated_at',
        ]);
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
