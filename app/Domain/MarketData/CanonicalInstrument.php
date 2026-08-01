<?php

namespace App\Domain\MarketData;

final readonly class CanonicalInstrument
{
    public function __construct(public string $value) {}
}
