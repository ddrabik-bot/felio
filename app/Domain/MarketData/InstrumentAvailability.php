<?php

namespace App\Domain\MarketData;

final readonly class InstrumentAvailability
{
    private function __construct(
        public ProviderInstrumentMapping $mapping,
        public AvailabilityStatus $status,
        public ?MarketDataError $error,
    ) {}

    public static function available(ProviderInstrumentMapping $mapping): self
    {
        return new self($mapping, AvailabilityStatus::Available, null);
    }

    public static function noData(ProviderInstrumentMapping $mapping): self
    {
        return new self($mapping, AvailabilityStatus::NoData, null);
    }

    public static function unavailable(ProviderInstrumentMapping $mapping, MarketDataError $error): self
    {
        return new self($mapping, AvailabilityStatus::Unavailable, $error);
    }
}
