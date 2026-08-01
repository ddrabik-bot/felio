<?php

namespace App\Domain\Fx;

final readonly class NbpFxRetryPolicy
{
    public function __construct(public int $maximumAttempts = 2)
    {
        if ($maximumAttempts < 1) {
            throw new \InvalidArgumentException('maximumAttempts must be at least 1.');
        }
    }

    public function shouldRetry(bool $retryable, int $attempt): bool
    {
        return $retryable && $attempt < $this->maximumAttempts;
    }
}
