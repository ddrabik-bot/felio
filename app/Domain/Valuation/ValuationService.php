<?php

namespace App\Domain\Valuation;

use App\Domain\Fx\FxRateAvailability;

final readonly class ValuationService
{
    public function __construct(private StaleFxRatePolicy $staleFxRatePolicy = StaleFxRatePolicy::Reject) {}

    public function value(ValuationInput $input): ValuationResult
    {
        if ($input->price->availability === ValuationAvailability::Unavailable) {
            return new ValuationResult(
                ValuationAvailability::Unavailable,
                null,
                [sprintf('price_unavailable:%s', $input->price->diagnostic ?? 'unspecified')],
            );
        }

        $marketValue = Decimal::multiply($input->quantity, $input->price->pricePerUnit);

        if ($input->price->currency !== 'PLN') {
            if ($input->fxRate === null) {
                return new ValuationResult(ValuationAvailability::Unavailable, null, ['fx_rate_missing']);
            }

            if ($input->fxRate->currency !== $input->price->currency
                || $input->fxRate->availability === FxRateAvailability::Unavailable
                || $input->fxRate->plnPerUnit === null) {
                return new ValuationResult(
                    ValuationAvailability::Unavailable,
                    null,
                    [sprintf('fx_rate_unavailable:%s', $input->fxRate->reason ?? 'unspecified')],
                );
            }

            if ($input->fxRate->availability === FxRateAvailability::Stale) {
                $diagnostic = $input->fxRate->reason ?? 'unspecified';

                if ($this->staleFxRatePolicy === StaleFxRatePolicy::Reject) {
                    return new ValuationResult(ValuationAvailability::Unavailable, null, ["fx_rate_stale_rejected:{$diagnostic}"]);
                }

                return new ValuationResult(
                    ValuationAvailability::Stale,
                    Decimal::roundPlnGrosz(Decimal::multiply($marketValue, $input->fxRate->plnPerUnit)),
                    ["fx_rate_stale_accepted:{$diagnostic}"],
                );
            }

            $marketValue = Decimal::multiply($marketValue, $input->fxRate->plnPerUnit);
        }

        return new ValuationResult(
            $input->price->availability,
            Decimal::roundPlnGrosz($marketValue),
            $input->price->diagnostic === null ? [] : [$input->price->diagnostic],
        );
    }
}
