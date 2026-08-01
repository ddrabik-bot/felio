<?php

namespace App\Domain\MarketData;

final readonly class InstrumentMarketData
{
    private function __construct(
        public ProviderInstrumentMapping $mapping,
        public AvailabilityStatus $status,
        public ?MarketDataSnapshot $snapshot,
        public ?MarketDataError $error,
    ) {}

    public static function available(ProviderInstrumentMapping $mapping, MarketDataSnapshot $snapshot): self
    {
        return new self($mapping, AvailabilityStatus::Available, $snapshot, null);
    }

    public static function noData(ProviderInstrumentMapping $mapping, MarketDataError $error): self
    {
        return new self($mapping, AvailabilityStatus::NoData, null, $error);
    }

    public static function unavailable(ProviderInstrumentMapping $mapping, MarketDataError $error): self
    {
        return new self($mapping, AvailabilityStatus::Unavailable, null, $error);
    }

    public function isAvailable(): bool
    {
        return $this->status === AvailabilityStatus::Available;
    }
}
