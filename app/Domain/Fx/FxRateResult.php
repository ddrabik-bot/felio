<?php

namespace App\Domain\Fx;

use DateTimeImmutable;

final readonly class FxRateResult
{
    public function __construct(
        public string $currency,
        public DateTimeImmutable $requestedDate,
        public ?DateTimeImmutable $effectiveDate,
        public FxRateAvailability $availability,
        public ?string $plnPerUnit,
        public ?string $reason,
        public int $attempts,
        public DateTimeImmutable $retrievedAt,
        public string $apiEndpoint,
        public string $table,
        public ?string $tableNumber,
        public string $sourceTimezone,
        public string $providerImplementationVersion,
        public string $providerApiContract,
    ) {}
}
