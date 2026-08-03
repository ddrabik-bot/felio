<?php

namespace App\Domain\Portfolio;

final readonly class PortfolioImportService
{
    public function __construct(private PortfolioImportRepository $repository) {}

    /** @param list<PortfolioImportRow> $rows */
    public function persist(ImportBatch $batch, array $rows): void
    {
        $this->repository->persist($batch, $rows);
    }
}
