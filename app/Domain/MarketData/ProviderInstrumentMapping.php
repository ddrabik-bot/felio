<?php

namespace App\Domain\MarketData;

final readonly class ProviderInstrumentMapping
{
    public function __construct(
        public CanonicalInstrument $instrument,
        public MarketDataProviderName $provider,
        public ProviderSymbol $symbol,
        public string $exchange,
        public string $quoteCurrency,
    ) {}
}
