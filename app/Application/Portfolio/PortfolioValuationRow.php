<?php

namespace App\Application\Portfolio;

use App\Domain\Fx\FxRateResult;
use App\Domain\Valuation\PriceQuote;
use App\Domain\Valuation\ValuationAvailability;

final readonly class PortfolioValuationRow
{
    /** @param list<string> $diagnostics */
    public function __construct(
        public int $positionId,
        public int $accountId,
        public string $instrument,
        public string $quantity,
        public PriceQuote $sourcePrice,
        public ?FxRateResult $fxRate,
        public ValuationAvailability $availability,
        public ?int $plnGrosze,
        public array $diagnostics,
    ) {}
}
