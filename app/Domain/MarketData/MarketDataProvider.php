<?php

namespace App\Domain\MarketData;

interface MarketDataProvider
{
    public function availability(ProviderInstrumentMapping $mapping): InstrumentAvailability;
}
