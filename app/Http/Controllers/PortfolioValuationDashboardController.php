<?php

namespace App\Http\Controllers;

use App\Application\Portfolio\PortfolioValuationRow;
use App\Application\Portfolio\PortfolioValuationService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PortfolioValuationDashboardController extends Controller
{
    public function __invoke(Request $request, PortfolioValuationService $valuationService): Response
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $valuationDate = new DateTimeImmutable($validated['date'].'T23:59:59+00:00');
        $valuation = $valuationService->read($valuationDate);

        return Inertia::render('Portfolio/ValuationDashboard', [
            'valuationDate' => $valuation->valuationDate->format('Y-m-d'),
            'totalPlnGrosze' => (string) $valuation->totalPlnGrosze,
            'state' => $valuation->rows === [] ? 'empty' : 'ready',
            'error' => null,
            'positions' => array_map($this->position(...), $valuation->rows),
        ]);
    }

    /** @return array<string, mixed> */
    private function position(PortfolioValuationRow $row): array
    {
        return [
            'instrument' => $row->instrument,
            'quantity' => $row->quantity,
            'availability' => $row->availability->value,
            'sourcePrice' => [
                'amount' => $row->sourcePrice->pricePerUnit,
                'currency' => $row->sourcePrice->currency,
                'availability' => $row->sourcePrice->availability->value,
                'diagnostic' => $row->sourcePrice->diagnostic,
            ],
            'fx' => $row->fxRate === null
                ? [
                    'status' => $row->sourcePrice->currency === 'PLN' ? 'not_required' : 'unavailable',
                    'amount' => null,
                    'currency' => $row->sourcePrice->currency === 'PLN' ? null : $row->sourcePrice->currency,
                    'diagnostic' => $row->sourcePrice->currency === 'PLN' ? null : 'fx_rate_missing',
                ]
                : [
                    'status' => $row->fxRate->availability->value,
                    'amount' => $row->fxRate->plnPerUnit,
                    'currency' => $row->fxRate->currency,
                    'diagnostic' => $row->fxRate->reason,
                ],
            'plnGrosze' => $row->plnGrosze,
            'diagnostics' => $row->diagnostics,
        ];
    }
}
