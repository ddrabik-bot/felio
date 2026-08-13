<?php

namespace App\Domain\Portfolio;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ImportBatch
{
    public function __construct(
        public string $broker,
        public ?string $accountReference,
        public string $sourceBatchIdentity,
        public DateTimeImmutable $importedAt,
        public ?int $portfolioAccountId = null,
    ) {
        foreach (['broker' => $broker, 'sourceBatchIdentity' => $sourceBatchIdentity] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$field} must not be empty.");
            }
        }

        if ($this->portfolioAccountId === null && ($this->accountReference === null || trim($this->accountReference) === '')) {
            throw new InvalidArgumentException('accountReference must not be empty when portfolioAccountId is absent.');
        }
    }
}
