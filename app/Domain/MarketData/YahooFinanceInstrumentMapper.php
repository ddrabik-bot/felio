<?php

namespace App\Domain\MarketData;

final class YahooFinanceInstrumentMapper
{
    public function map(CanonicalInstrument $instrument): ?ProviderInstrumentMapping
    {
        if ($instrument->value === 'OTLK.US') {
            return $this->mapping($instrument, 'OTLK', 'NCM', 'USD');
        }

        if (str_ends_with($instrument->value, '.PL')) {
            return $this->mapping(
                $instrument,
                substr($instrument->value, 0, -3).'.WA',
                'WSE',
                'PLN',
            );
        }

        if (str_ends_with($instrument->value, '.DE')) {
            return $this->mapping($instrument, $instrument->value, 'GER', 'EUR');
        }

        return null;
    }

    private function mapping(
        CanonicalInstrument $instrument,
        string $symbol,
        string $exchange,
        string $quoteCurrency,
    ): ProviderInstrumentMapping {
        return new ProviderInstrumentMapping(
            $instrument,
            MarketDataProviderName::YahooFinance,
            new ProviderSymbol($symbol),
            $exchange,
            $quoteCurrency,
        );
    }
}
