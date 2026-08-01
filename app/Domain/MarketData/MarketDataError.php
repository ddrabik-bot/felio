<?php

namespace App\Domain\MarketData;

final readonly class MarketDataError
{
    private function __construct(
        public MarketDataErrorCategory $category,
        public string $diagnostic,
    ) {}

    public static function from(MarketDataErrorCategory $category, string $diagnostic): self
    {
        return new self($category, $diagnostic);
    }

    public function retryable(): bool
    {
        return match ($this->category) {
            MarketDataErrorCategory::Transport,
            MarketDataErrorCategory::RateLimited => true,
            MarketDataErrorCategory::InstrumentNotFound,
            MarketDataErrorCategory::InvalidResponse => false,
        };
    }
}
