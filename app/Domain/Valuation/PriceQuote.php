<?php

namespace App\Domain\Valuation;

use InvalidArgumentException;

final readonly class PriceQuote
{
    public function __construct(
        public string $currency,
        public ?string $pricePerUnit,
        public ValuationAvailability $availability,
        public ?string $diagnostic = null,
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Price currency must be a three-letter uppercase ISO code.');
        }

        if ($pricePerUnit === null) {
            if ($availability !== ValuationAvailability::Unavailable) {
                throw new InvalidArgumentException('An available or stale price must include a positive price per unit.');
            }

            return;
        }

        Decimal::positive($pricePerUnit, 'Price per unit');
    }
}
