<?php

namespace App\Application\Portfolio\Xtb;

use App\Domain\Portfolio\PortfolioImportRow;

final readonly class XtbImportAnalysis
{
    /** @param list<PortfolioImportRow> $rows */
    public function __construct(
        public string $accountReference,
        public string $batchIdentity,
        public array $rows,
    ) {}

    /** @return array{valid: int, pending: int, rejected: int} */
    public function summary(): array
    {
        $summary = ['valid' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($this->rows as $row) {
            $summary[$row->status->value]++;
        }

        return $summary;
    }
}
