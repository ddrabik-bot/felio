<?php

namespace App\Domain\MarketData;

final readonly class ProviderSymbol
{
    public function __construct(public string $value) {}
}
