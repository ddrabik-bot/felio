<?php

namespace App\Application\Portfolio;

use DateTimeImmutable;

final readonly class PortfolioValuationReadModel
{
    /** @param list<PortfolioValuationRow> $rows */
    public function __construct(
        public DateTimeImmutable $valuationDate,
        public array $rows,
        public int $totalPlnGrosze,
    ) {}
}
