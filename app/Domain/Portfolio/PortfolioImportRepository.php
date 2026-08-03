<?php

namespace App\Domain\Portfolio;

interface PortfolioImportRepository
{
    /** @param list<PortfolioImportRow> $rows */
    public function persist(ImportBatch $batch, array $rows): void;
}
