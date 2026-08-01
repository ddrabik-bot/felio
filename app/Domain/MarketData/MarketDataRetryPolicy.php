<?php

namespace App\Domain\MarketData;

final readonly class MarketDataRetryPolicy
{
    public function __construct(public int $maximumAttempts = 2) {}

    public function shouldRetry(MarketDataError $error, int $attempt): bool
    {
        return $error->retryable() && $attempt < $this->maximumAttempts;
    }
}
