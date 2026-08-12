<?php

namespace App\Application\Scheduling;

use App\Application\Portfolio\PortfolioValuationService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class DailyPortfolioSnapshotService
{
    public function __construct(private PortfolioValuationService $valuationService) {}

    public function snapshot(DateTimeImmutable $now): void
    {
        $target = $now->setTimezone(new DateTimeZone('Europe/Warsaw'))->setTime(0, 0);

        foreach (DB::table('portfolio_accounts')->where('is_active', true)->orderBy('id')->pluck('id') as $accountId) {
            $this->backfill((int) $accountId, $target);
        }
    }

    private function backfill(int $accountId, DateTimeImmutable $target): void
    {
        $firstPosition = DB::table('portfolio_import_source_rows as rows')
            ->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')
            ->where('batches.portfolio_account_id', $accountId)->where('rows.status', 'valid')->whereNotNull('rows.canonical_instrument')
            ->min('rows.as_of');
        if ($firstPosition === null) {
            return;
        }

        $date = (new DateTimeImmutable($firstPosition))->setTimezone(new DateTimeZone('Europe/Warsaw'))->setTime(0, 0);
        while ($date <= $target) {
            $this->persist($accountId, $date);
            $date = $date->add(new DateInterval('P1D'));
        }
    }

    private function persist(int $accountId, DateTimeImmutable $date): void
    {
        $read = $this->valuationService->read($date, $accountId);
        $available = count($read->rows) > 0 && collect($read->rows)->every(static fn ($row): bool => $row->plnGrosze !== null);
        $diagnostics = $read->rows === [] ? [] : array_values(array_unique(array_merge(...array_map(static fn ($row): array => $row->diagnostics, $read->rows))));
        $total = $available ? $read->totalPlnGrosze : null;
        $source = 'confirmed_positions_exact_date';

        if (! $available) {
            $previous = DB::table('portfolio_value_snapshots')
                ->where('portfolio_account_id', $accountId)->where('availability', 'available')
                ->where('valuation_date', '<', $date->format('Y-m-d'))->orderByDesc('valuation_date')->first();
            if ($previous !== null) {
                $available = true;
                $total = (int) $previous->total_pln_grosze;
                $source = 'prior_snapshot_forward_fill';
                $diagnostics[] = 'forward_filled_from:'.$previous->valuation_date;
            }
        }

        DB::table('portfolio_value_snapshots')->upsert([[
            'portfolio_account_id' => $accountId,
            'valuation_date' => $date->format('Y-m-d'),
            'availability' => $available ? 'available' : 'unavailable',
            'total_pln_grosze' => $total,
            'source' => $source,
            'diagnostics' => json_encode($diagnostics, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['portfolio_account_id', 'valuation_date'], ['availability', 'total_pln_grosze', 'source', 'diagnostics', 'updated_at']);
    }
}
