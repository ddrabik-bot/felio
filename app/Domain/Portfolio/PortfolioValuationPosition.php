<?php

namespace App\Domain\Portfolio;

final readonly class PortfolioValuationPosition
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $canonicalInstrument,
        public string $quantity,
    ) {}
}
