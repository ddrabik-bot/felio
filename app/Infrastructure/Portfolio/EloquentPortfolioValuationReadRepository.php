<?php

namespace App\Infrastructure\Portfolio;

use App\Domain\Fx\FxRateAvailability;
use App\Domain\Fx\FxRateResult;
use App\Domain\Portfolio\PortfolioPositionProjection;
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
            ->select(['rows.id', 'batches.portfolio_account_id', 'rows.canonical_instrument', 'rows.quantity', 'rows.average_cost_pln_grosze', 'rows.as_of', 'rows.raw_values', 'batches.imported_at', 'batches.id as batch_id'])
            ->where('rows.status', 'valid')
            ->whereNotNull('rows.canonical_instrument')
            ->where('batches.portfolio_account_id', $portfolioAccountId)
            ->where('rows.as_of', '<=', $asOf)
            ->orderBy('batches.portfolio_account_id')
            ->orderBy('rows.canonical_instrument')
            ->orderBy('rows.as_of')
            ->orderBy('batches.imported_at')
            ->orderBy('batches.id')
            ->orderBy('rows.id')
            ->get();

        $projection = PortfolioPositionProjection::rebuild($rows->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'canonicalInstrument' => $row->canonical_instrument,
            'quantity' => (string) $row->quantity,
            'averageCostPlnGrosze' => $row->average_cost_pln_grosze === null ? null : (int) $row->average_cost_pln_grosze,
            'asOf' => $row->as_of,
            'sourceImportBatchId' => (int) $row->batch_id,
            'rawValues' => json_decode($row->raw_values, true, 512, JSON_THROW_ON_ERROR),
        ]));

        return array_map(static fn (array $position): PortfolioValuationPosition => new PortfolioValuationPosition(
            $position['id'],
            $portfolioAccountId,
            $position['canonicalInstrument'],
            $position['quantity'],
            $position['averageCostPlnGrosze'],
        ), array_values($projection));
    }

    public function priceFor(string $canonicalInstrument, DateTimeImmutable $valuationDate): PriceQuote
    {
        $date = $valuationDate->format('Y-m-d');
        $candidates = DB::table('market_data_snapshots as snapshots')
            ->join('daily_ohlc_observations as observations', 'observations.market_data_snapshot_id', '=', 'snapshots.id')
            ->where('snapshots.canonical_instrument', $canonicalInstrument)
            ->whereColumn('snapshots.session_date', 'observations.trading_date')
            ->where('snapshots.session_date', '<=', $date)
            ->where('observations.trading_date', '<=', $date)
            ->orderByDesc('observations.trading_date')
            ->orderBy('snapshots.provider')
            ->orderBy('snapshots.provider_symbol')
            ->orderBy('snapshots.id')
            ->select(['snapshots.quote_currency', 'observations.close', 'observations.trading_date'])
            ->get();

        if ($candidates->isEmpty()) {
            return new PriceQuote('PLN', null, ValuationAvailability::Unavailable, 'market_price_missing_on_or_before_date');
        }
        $usedDate = $candidates->first()->trading_date;
        $candidates = $candidates->where('trading_date', $usedDate)->values();
        if ($candidates->count() !== 1) {
            return new PriceQuote('PLN', null, ValuationAvailability::Unavailable, 'market_price_ambiguous_on_used_date');
        }

        $candidate = $candidates->first();

        $isForwardFilled = $usedDate !== $date;

        return new PriceQuote(
            $candidate->quote_currency,
            (string) $candidate->close,
            $isForwardFilled ? ValuationAvailability::Stale : ValuationAvailability::Available,
            $isForwardFilled ? 'market_price_forward_filled' : null,
            new DateTimeImmutable($usedDate.'T00:00:00+00:00'),
        );
    }

    public function fxRateFor(string $currency, DateTimeImmutable $valuationDate): ?FxRateResult
    {
        $date = $valuationDate->format('Y-m-d');
        $candidates = DB::table('fx_rate_snapshots')
            ->where('currency', $currency)
            ->where('requested_date', '<=', $date)
            ->whereIn('availability', [FxRateAvailability::Available->value, FxRateAvailability::Stale->value])
            ->whereNotNull('pln_per_unit')
            ->orderByDesc('effective_date')
            ->orderByDesc('requested_date')
            ->orderBy('provider_implementation_version')
            ->orderBy('source_observation_identity')
            ->orderBy('id')
            ->get();
        if ($candidates->isEmpty()) {
            return null;
        }
        $usedDate = $candidates->first()->effective_date;
        $candidates = $candidates->where('effective_date', $usedDate)->values();
        if ($candidates->count() !== 1) {
            return $this->unavailableFxRate($currency, $valuationDate, 'fx_rate_ambiguous_on_used_date');
        }

        $candidate = $candidates->first();

        return new FxRateResult($candidate->currency, $valuationDate, $candidate->effective_date === null ? null : new DateTimeImmutable($candidate->effective_date.'T00:00:00+00:00'), FxRateAvailability::from($candidate->availability), (string) $candidate->pln_per_unit, $candidate->reason, (int) $candidate->attempts, new DateTimeImmutable($candidate->retrieved_at), $candidate->api_endpoint, $candidate->table, null, $candidate->source_timezone, $candidate->provider_implementation_version, 'persisted_snapshot');
    }

    private function unavailableFxRate(string $currency, DateTimeImmutable $valuationDate, string $reason): FxRateResult
    {
        return new FxRateResult($currency, $valuationDate, null, FxRateAvailability::Unavailable, null, $reason, 0, $valuationDate, 'persisted_snapshot', 'persisted', null, 'UTC', 'portfolio-valuation-read-model', 'persisted_snapshot');
    }
}
