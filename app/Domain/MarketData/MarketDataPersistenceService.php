<?php

namespace App\Domain\MarketData;

final readonly class MarketDataPersistenceService
{
    public function __construct(private MarketDataPersistenceRepository $repository) {}

    public function persist(InstrumentMarketData $marketData, string $sessionDate): void
    {
        if (! $marketData->isAvailable() || $marketData->snapshot === null) {
            return;
        }

        $this->repository->persist($marketData, $sessionDate);
    }
}
