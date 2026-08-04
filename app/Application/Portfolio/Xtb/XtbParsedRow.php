<?php

namespace App\Application\Portfolio\Xtb;

use DateTimeImmutable;

final readonly class XtbParsedRow
{
    /** @param array<string, string> $rawValues */
    public function __construct(
        public string $sheet,
        public string $sourceRowReference,
        public ?string $operation,
        public ?string $symbol,
        public ?string $instrumentLabel,
        public ?string $quantity,
        public ?string $price,
        public DateTimeImmutable $asOf,
        public array $rawValues,
        public ?string $diagnostic,
    ) {}

    public function isValidCashTrade(): bool
    {
        return $this->sheet === 'Cash Operations'
            && $this->diagnostic === null
            && in_array($this->operation, ['buy', 'sell'], true);
    }
}
