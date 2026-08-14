<?php

namespace App\Domain\MarketData;

final class YahooFinanceInstrumentMapper
{
    public function map(CanonicalInstrument $instrument): ?ProviderInstrumentMapping
    {
        foreach ([
            'OTLK.US' => ['OTLK', 'NCM', 'USD'],
            'ARMG.UK' => ['ARMG.L', 'LSE', 'GBP'],
            'SPCE.US' => ['SPCE', 'NYQ', 'USD'],
            'SPXC.US' => ['SPXC', 'NYQ', 'USD'],
        ] as $canonicalInstrument => [$symbol, $exchange, $quoteCurrency]) {
            if ($instrument->value === $canonicalInstrument) {
                return $this->mapping($instrument, $symbol, $exchange, $quoteCurrency);
            }
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
