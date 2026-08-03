<?php

namespace App\Domain\Portfolio;

use App\Domain\Fx\FxRateResult;
use App\Domain\Valuation\PriceQuote;
use DateTimeImmutable;

interface PortfolioValuationReadRepository
{
    /** @return list<PortfolioValuationPosition> */
    public function positionsAsOf(DateTimeImmutable $valuationDate): array;

    public function priceFor(string $canonicalInstrument, DateTimeImmutable $valuationDate): PriceQuote;

    public function fxRateFor(string $currency, DateTimeImmutable $valuationDate): ?FxRateResult;
}
