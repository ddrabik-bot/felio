<?php

namespace App\Domain\Fx;

final readonly class FxRatePersistenceService
{
    public function __construct(private FxRatePersistenceRepository $repository) {}

    public function persist(FxRateResult $result): void
    {
        $this->repository->persist($result);
    }
}
