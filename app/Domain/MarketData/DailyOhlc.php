<?php

namespace App\Domain\MarketData;

final readonly class DailyOhlc
{
    public function __construct(
        public string $date,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
    ) {}
}
