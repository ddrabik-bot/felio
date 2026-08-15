<?php

namespace App\Http\Controllers;

use App\Application\Portfolio\PortfolioValuationReadModel;
use App\Application\Portfolio\PortfolioValuationRow;
use App\Application\Portfolio\PortfolioValuationService;
use App\Domain\Portfolio\AssetClassRegistry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PortfolioValuationDashboardController extends Controller
{
    public function __invoke(Request $request, PortfolioValuationService $valuationService, AssetClassRegistry $assetClasses): Response|RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'range' => ['nullable', 'in:1D,WTD,MTD,1M,3M,YTD,MAX'],
        ]);
        $valuationDate = new DateTimeImmutable($validated['date'].'T23:59:59+00:00');
        $activePortfolio = DB::table('portfolio_accounts')->where('user_id', $request->user()->id)->where('is_active', true)->first();
        if ($activePortfolio === null) {
            return redirect('/portfolio/onboarding');
        }

        $computed = $valuationService->read($valuationDate, $activePortfolio->id);
        $valuation = $this->selectedValuation($computed);
        $range = $validated['range'] ?? 'MAX';
        $history = $this->history($valuationService, $activePortfolio->id, $valuationDate, $range, $computed);
        $allocation = $this->allocation($computed->rows, $computed->totalPlnGrosze, $assetClasses);
        $firstAvailable = collect($history)->first(static fn (array $point): bool => $point['availability'] === 'available');
        $periodReturn = $firstAvailable === null || $valuation['totalPlnGrosze'] === null ? null : $this->returnBps($firstAvailable['totalPlnGrosze'], $valuation['totalPlnGrosze']);
        $invested = $this->investedCapital($computed->rows);
        $profitLoss = $invested === null || $valuation['totalPlnGrosze'] === null ? null : bcsub($valuation['totalPlnGrosze'], $invested, 0);

        return Inertia::render('Portfolio/ValuationDashboard', [
            'valuationDate' => $valuationDate->format('Y-m-d'),
            'activePortfolio' => ['id' => $activePortfolio->id, 'broker' => $activePortfolio->broker, 'accountReference' => $activePortfolio->account_reference],
            'totalPlnGrosze' => $valuation['totalPlnGrosze'],
            'valuation' => $valuation,
            'range' => $range,
            'state' => $computed->rows === [] ? 'empty' : 'ready',
            'error' => null,
            'positions' => array_map($this->position(...), $computed->rows),
            'history' => $history,
            'allocation' => $allocation,
            'kpis' => [
                'totalValuePlnGrosze' => $valuation['totalPlnGrosze'],
                'partialValuePlnGrosze' => $valuation['partialTotalPlnGrosze'],
                'investedCapitalPlnGrosze' => $invested,
                'investedCapitalAvailability' => $invested === null ? 'unavailable' : 'available',
                'profitLossPlnGrosze' => $profitLoss,
                'profitLossAvailability' => $profitLoss === null ? 'unavailable' : 'available',
                'periodReturnBps' => $periodReturn,
                'periodReturnAvailability' => $periodReturn === null ? 'unavailable' : 'available',
            ],
        ]);
    }

    /** @return array{totalPlnGrosze: ?string, partialTotalPlnGrosze: ?string, availability: string, source: string, diagnostics: list<string>, warnings: list<string>} */
    private function selectedValuation(PortfolioValuationReadModel $computed): array
    {
        $hasPositions = $computed->rows !== [];
        $allAvailable = $hasPositions && collect($computed->rows)->every(static fn (PortfolioValuationRow $row): bool => $row->plnGrosze !== null);
        $hasAvailableValue = collect($computed->rows)->contains(static fn (PortfolioValuationRow $row): bool => $row->plnGrosze !== null);
        $diagnostics = $hasPositions
            ? array_values(array_unique(array_merge(...array_map(static fn (PortfolioValuationRow $row): array => $row->diagnostics, $computed->rows))))
            : ['no_confirmed_positions'];

        return [
            'totalPlnGrosze' => $allAvailable ? (string) $computed->totalPlnGrosze : null,
            'partialTotalPlnGrosze' => $allAvailable || ! $hasAvailableValue ? null : (string) $computed->totalPlnGrosze,
            'availability' => $allAvailable ? 'available' : ($hasAvailableValue ? 'incomplete' : 'unavailable'),
            'source' => 'computed_read_model',
            'diagnostics' => $allAvailable ? [] : $diagnostics,
            'warnings' => $allAvailable || ! $hasAvailableValue ? [] : ['incomplete_valuation'],
        ];
    }

    /** @return list<array{date: string, availability: string, totalPlnGrosze: ?string, source: string, diagnostics: list<string>}> */
    private function history(PortfolioValuationService $service, int $accountId, DateTimeImmutable $end, string $range, PortfolioValuationReadModel $current): array
    {
        $start = max($this->rangeStart($end, $range, $accountId), $this->firstValuationDate($accountId, $end->setTime(0, 0)));
        $snapshots = DB::table('portfolio_value_snapshots')->where('portfolio_account_id', $accountId)->whereBetween('valuation_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->orderBy('valuation_date')->get()->keyBy('valuation_date');
        $points = [];
        for ($date = $start; $date <= $end; $date = $date->add(new DateInterval('P1D'))) {
            $key = $date->format('Y-m-d');
            $snapshot = $key === $end->format('Y-m-d') ? null : $snapshots->get($key);
            if ($snapshot !== null) {
                $points[] = $this->snapshotPoint($key, $snapshot);

                continue;
            }
            $read = $key === $end->format('Y-m-d') ? $current : $service->read($date, $accountId);
            $points[] = $this->selectedValuationPoint($key, $this->selectedValuation($read));
        }

        return $points;
    }

    /** @return array{date: string, availability: string, totalPlnGrosze: ?string, source: string, diagnostics: list<string>} */
    private function snapshotPoint(string $date, object $snapshot): array
    {
        return $this->selectedValuationPoint($date, ['totalPlnGrosze' => $snapshot->total_pln_grosze === null ? null : (string) $snapshot->total_pln_grosze, 'availability' => $snapshot->availability, 'source' => $snapshot->source, 'diagnostics' => json_decode($snapshot->diagnostics, true, 512, JSON_THROW_ON_ERROR)]);
    }

    /** @param array{totalPlnGrosze: ?string, availability: string, source: string, diagnostics: list<string>} $value @return array{date: string, availability: string, totalPlnGrosze: ?string, source: string, diagnostics: list<string>} */
    private function selectedValuationPoint(string $date, array $value): array
    {
        return ['date' => $date, ...$value];
    }

    private function rangeStart(DateTimeImmutable $end, string $range, int $accountId): DateTimeImmutable
    {
        $day = $end->setTime(0, 0);

        return match ($range) {
            '1D' => $day, 'WTD' => $day->sub(new DateInterval('P'.((int) $day->format('N') - 1).'D')), 'MTD' => $day->modify('first day of this month'), '1M' => $day->sub(new DateInterval('P1M')), '3M' => $day->sub(new DateInterval('P3M')), 'YTD' => $day->modify('first day of January'), default => $this->firstValuationDate($accountId, $day),
        };
    }

    private function firstValuationDate(int $accountId, DateTimeImmutable $fallback): DateTimeImmutable
    {
        $first = DB::table('portfolio_value_snapshots')->where('portfolio_account_id', $accountId)->min('valuation_date') ?? DB::table('portfolio_import_source_rows as rows')->join('portfolio_import_batches as batches', 'batches.id', '=', 'rows.portfolio_import_batch_id')->where('batches.portfolio_account_id', $accountId)->where('rows.status', 'valid')->whereNotNull('rows.canonical_instrument')->min('rows.as_of');

        return $first === null ? $fallback : (new DateTimeImmutable($first))->setTime(0, 0);
    }

    /** @param list<PortfolioValuationRow> $rows @return array{availability: string, byHolding: list<array<string, string>>, byAssetClass: list<array<string, string>>} */
    private function allocation(array $rows, int $total, AssetClassRegistry $assetClasses): array
    {
        if ($rows === [] || $total <= 0 || ! collect($rows)->every(static fn (PortfolioValuationRow $row): bool => $row->plnGrosze !== null)) {
            return ['availability' => 'unavailable', 'byHolding' => [], 'byAssetClass' => []];
        }
        $holdings = array_map(fn (PortfolioValuationRow $row): array => ['instrument' => $row->instrument, 'valuePlnGrosze' => (string) $row->plnGrosze], $rows);
        $classes = [];
        foreach ($rows as $row) {
            $class = $assetClasses->forCanonicalInstrument($row->instrument)->value;
            $classes[$class] = bcadd($classes[$class] ?? '0', (string) $row->plnGrosze, 0);
        }

        return ['availability' => 'available', 'byHolding' => $this->weighted($holdings, $total, 'instrument'), 'byAssetClass' => $this->weighted(array_map(static fn (string $value, string $assetClass): array => ['assetClass' => $assetClass, 'valuePlnGrosze' => $value], $classes, array_keys($classes)), $total, 'assetClass')];
    }

    /** @param list<array<string, string>> $items @return list<array<string, string>> */
    private function weighted(array $items, int $total, string $key): array
    {
        $sum = 0;
        foreach ($items as &$item) {
            $item['weightBps'] = bcdiv(bcmul($item['valuePlnGrosze'], '10000', 0), (string) $total, 0);
            $sum += (int) $item['weightBps'];
        } unset($item);
        $items[array_key_last($items)]['weightBps'] = (string) ((int) $items[array_key_last($items)]['weightBps'] + 10000 - $sum);
        usort($items, static fn (array $a, array $b): int => $a[$key] <=> $b[$key]);

        return $items;
    }

    /** @param list<PortfolioValuationRow> $rows */
    private function investedCapital(array $rows): ?string
    {
        if ($rows === [] || collect($rows)->contains(static fn (PortfolioValuationRow $row): bool => $row->averageCostPlnGrosze === null)) {
            return null;
        }

        return array_reduce($rows, static fn (string $total, PortfolioValuationRow $row): string => bcadd($total, (string) $row->averageCostPlnGrosze, 0), '0');
    }

    private function returnBps(string $start, string $end): ?string
    {
        return bccomp($start, '0', 0) !== 1 ? null : bcdiv(bcmul(bcsub($end, $start, 0), '10000', 0), $start, 0);
    }

    /** @return array<string, mixed> */
    private function position(PortfolioValuationRow $row): array
    {
        return ['instrument' => $row->instrument, 'quantity' => $row->quantity, 'availability' => $row->availability->value, 'sourcePrice' => ['amount' => $row->sourcePrice->pricePerUnit, 'currency' => $row->sourcePrice->currency, 'availability' => $row->sourcePrice->availability->value, 'usedDate' => $row->sourcePrice->usedDate?->format('Y-m-d'), 'diagnostic' => $row->sourcePrice->diagnostic], 'fx' => $row->fxRate === null ? ['status' => $row->sourcePrice->currency === 'PLN' ? 'not_required' : 'unavailable', 'amount' => null, 'currency' => $row->sourcePrice->currency === 'PLN' ? null : $row->sourcePrice->currency, 'requestedDate' => null, 'usedDate' => null, 'diagnostic' => $row->sourcePrice->currency === 'PLN' ? null : 'fx_rate_missing'] : ['status' => $row->fxRate->availability->value, 'amount' => $row->fxRate->plnPerUnit, 'currency' => $row->fxRate->currency, 'requestedDate' => $row->fxRate->requestedDate->format('Y-m-d'), 'usedDate' => $row->fxRate->effectiveDate?->format('Y-m-d'), 'diagnostic' => $row->fxRate->reason], 'plnGrosze' => $row->plnGrosze, 'diagnostics' => $row->diagnostics];
    }
}
