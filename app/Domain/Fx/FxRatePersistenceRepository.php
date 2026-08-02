<?php

namespace App\Domain\Fx;

interface FxRatePersistenceRepository
{
    public function persist(FxRateResult $result): void;
}
