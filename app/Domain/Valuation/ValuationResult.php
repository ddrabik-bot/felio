<?php

namespace App\Domain\Valuation;

final readonly class ValuationResult
{
    /** @param list<string> $diagnostics */
    public function __construct(
        public ValuationAvailability $availability,
        public ?string $marketValuePln,
        public array $diagnostics,
    ) {}
}
