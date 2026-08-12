<?php

namespace App\Infrastructure\Portfolio;

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRateResult;
use App\Domain\Portfolio\PortfolioValuationPosition;
use App\Domain\Portfolio\PortfolioValuationReadRepository;
use App\Domain\Valuation\PriceQuote;
use App\Domain\Valuation\ValuationAvailability;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class EloquentPortfolioValuationReadRepository implements PortfolioValuationReadRepository
{
    /** @return list<PortfolioValuationPosition> */
    public function positionsAsOf(DateTimeImmutable $valuationDate, int $portfolioAccountId): array
    {
        $asOf = $valuationDate->format('Y-m-d').'T23:59:59+00:00';
        $rows = DB::table('portfolio_import_source_rows as rows')
            ->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')
            ->select(['rows.id', 'batches.portfolio_account_id', 'rows.canonical_instrument', 'rows.quantity', 'rows.average_cost_pln_grosze', 'rows.as_of', 'batches.imported_at', 'batches.id as batch_id'])
            ->where('rows.status', 'valid')
            ->whereNotNull('rows.canonical_instrument')
            ->where('batches.portfolio_account_id', $portfolioAccountId)
            ->where('rows.as_of', '<=', $asOf)
            ->orderBy('batches.portfolio_account_id')
            ->orderBy('rows.canonical_instrument')
            ->orderByDesc('rows.as_of')
            ->orderByDesc('batches.imported_at')
            ->orderByDesc('batches.id')
            ->orderByDesc('rows.id')
            ->get();

        $positions = [];
        foreach ($rows as $row) {
            $key = $row->portfolio_account_id."\x1f".$row->canonical_instrument;
            if (isset($positions[$key])) {
                continue;
            }

            $positions[$key] = new PortfolioValuationPosition((int) $row->id, (int) $row->portfolio_account_id, $row->canonical_instrument, (string) $row->quantity, $row->average_cost_pln_grosze === null ? null : (int) $row->average_cost_pln_grosze);
        }

        return array_values($positions);
    }

    public function priceFor(string $canonicalInstrument, DateTimeImmutable $valuationDate): PriceQuote
    {
        $date = $valuationDate->format('Y-m-d');
        $candidates = DB::table('market_data_snapshots as snapshots')
            ->join('daily_ohlc_observations as observations', 'observations.market_data_snapshot_id', '=', 'snapshots.id')
            ->where('snapshots.canonical_instrument', $canonicalInstrument)
            ->where('snapshots.session_date', $date)
            ->where('observations.trading_date', $date)
            ->orderBy('snapshots.provider')
            ->orderBy('snapshots.provider_symbol')
            ->orderBy('snapshots.id')
            ->select(['snapshots.quote_currency', 'observations.close'])
            ->get();

        if ($candidates->isEmpty()) {
            return new PriceQuote('PLN', null, ValuationAvailability::Unavailable, 'market_price_missing_exact_date');
        }
        if ($candidates->count() !== 1) {
            return new PriceQuote('PLN', null, ValuationAvailability::Unavailable, 'market_price_ambiguous_exact_date');
        }

        $candidate = $candidates->first();

        return new PriceQuote($candidate->quote_currency, (string) $candidate->close, ValuationAvailability::Available);
    }

    public function fxRateFor(string $currency, DateTimeImmutable $valuationDate): ?FxRateResult
    {
        $candidates = DB::table('fx_rate_snapshots')->where('currency', $currency)->where('requested_date', $valuationDate->format('Y-m-d'))
            ->orderBy('provider_implementation_version')->orderBy('source_observation_identity')->orderBy('id')->get();
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($candidates->count() !== 1) {
            return $this->unavailableFxRate($currency, $valuationDate, 'fx_rate_ambiguous_exact_date');
        }

        $candidate = $candidates->first();

        return new FxRateResult($candidate->currency, new DateTimeImmutable($candidate->requested_date.'T00:00:00+00:00'), $candidate->effective_date === null ? null : new DateTimeImmutable($candidate->effective_date.'T00:00:00+00:00'), FxRateAvailability::from($candidate->availability), $candidate->pln_per_unit === null ? null : (string) $candidate->pln_per_unit, $candidate->reason, (int) $candidate->attempts, new DateTimeImmutable($candidate->retrieved_at), $candidate->api_endpoint, $candidate->table, null, $candidate->source_timezone, $candidate->provider_implementation_version, 'persisted_snapshot');
    }

    private function unavailableFxRate(string $currency, DateTimeImmutable $valuationDate, string $reason): FxRateResult
    {
        return new FxRateResult($currency, $valuationDate, null, FxRateAvailability::Unavailable, null, $reason, 0, $valuationDate, 'persisted_snapshot', 'persisted', null, 'UTC', 'portfolio-valuation-read-model', 'persisted_snapshot');
    }
}
