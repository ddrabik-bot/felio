<?php

namespace App\Domain\Fx;

use DateTimeImmutable;

interface FxRateProvider
{
    public function historical(string $currency, DateTimeImmutable $requestedDate): FxRateResult;

    public function current(string $currency, DateTimeImmutable $asOfDate): FxRateResult;

    /**
     * @param  list<string>  $currencies
     * @return list<FxRateResult>
     */
    public function historicalMany(array $currencies, DateTimeImmutable $requestedDate): array;
}
