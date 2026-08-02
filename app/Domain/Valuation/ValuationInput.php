<?php

namespace App\Domain\Valuation;

use App\Domain\Fx\FxRateResult;

final readonly class ValuationInput
{
    public function __construct(
        public string $quantity,
        public PriceQuote $price,
        public ?FxRateResult $fxRate = null,
    ) {
        Decimal::positive($quantity, 'Quantity');

        if ($fxRate?->plnPerUnit !== null) {
            Decimal::positive($fxRate->plnPerUnit, 'FX PLN per unit');
        }
    }
}
