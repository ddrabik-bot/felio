<?php

use App\Domain\Portfolio\AssetClass;
use App\Domain\Portfolio\AssetClassRegistry;

it('classifies a known canonical instrument deterministically via the registry', function (): void {
    $registry = new AssetClassRegistry();

    expect($registry->forCanonicalInstrument('PZU.PL'))->toBe(AssetClass::Equity)
        ->and($registry->forCanonicalInstrument('ETF.PL'))->toBe(AssetClass::ExchangeTradedFund)
        ->and($registry->forCanonicalInstrument('BOND.US'))->toBe(AssetClass::FixedIncome);
});

it('is repeatable and does not depend on exchange suffix', function (): void {
    $registry = new AssetClassRegistry();

    // Same canonical instrument always yields the same class.
    expect($registry->forCanonicalInstrument('PZU.PL'))->toBe(AssetClass::Equity)
        ->and($registry->forCanonicalInstrument('PZU.PL'))->toBe(AssetClass::Equity);

    // A US-traded unknown symbol is NOT assumed to be Equity just because of its suffix.
    expect($registry->forCanonicalInstrument('BOND.US'))->toBe(AssetClass::FixedIncome)
        ->and($registry->forCanonicalInstrument('RANDOM.US'))->toBe(AssetClass::Unclassified);
});

it('falls back to Unclassified for any instrument not in the registry', function (): void {
    $registry = new AssetClassRegistry();

    expect($registry->forCanonicalInstrument('GOLD.DE'))->toBe(AssetClass::Unclassified)
        ->and($registry->forCanonicalInstrument(''))->toBe(AssetClass::Unclassified)
        ->and($registry->forCanonicalInstrument('unknown'))->toBe(AssetClass::Unclassified);
});

it('exposes a bounded set of typed asset-class values', function (): void {
    expect(AssetClass::cases())->toHaveCount(5)
        ->and(AssetClass::Equity->value)->toBe('Equity')
        ->and(AssetClass::ExchangeTradedFund->value)->toBe('Exchange-traded fund')
        ->and(AssetClass::FixedIncome->value)->toBe('Fixed income')
        ->and(AssetClass::Cash->value)->toBe('Cash')
        ->and(AssetClass::Unclassified->value)->toBe('Unclassified');
});