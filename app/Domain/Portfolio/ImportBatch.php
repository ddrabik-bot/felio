<?php

namespace App\Domain\Portfolio;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ImportBatch
{
    public function __construct(
        public string $broker,
        public string $accountReference,
        public string $sourceBatchIdentity,
        public DateTimeImmutable $importedAt,
    ) {
        foreach (['broker' => $broker, 'accountReference' => $accountReference, 'sourceBatchIdentity' => $sourceBatchIdentity] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$field} must not be empty.");
            }
        }
    }
}
