<?php

namespace App\Domain\Portfolio;

final class AssetClassRegistry
{
    /** @var array<string, AssetClass> */
    private const CLASSES = [
        'PZU.PL' => AssetClass::Equity,
        'ETF.PL' => AssetClass::ExchangeTradedFund,
        'BOND.US' => AssetClass::FixedIncome,
    ];

    public function forCanonicalInstrument(string $canonicalInstrument): AssetClass
    {
        return self::CLASSES[$canonicalInstrument] ?? AssetClass::Unclassified;
    }
}
