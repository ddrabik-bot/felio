<?php

namespace App\Domain\MarketData;

interface MarketDataPersistenceRepository
{
    public function persist(InstrumentMarketData $marketData, string $sessionDate): void;
}
