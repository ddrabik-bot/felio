<?php

namespace App\Domain\MarketData;

final readonly class MarketDataSnapshot
{
    /**
     * @param  list<DailyOhlc>  $dailyOhlc
     * @param  list<array{date: string, amount: string}>  $dividends
     * @param  list<array{date: string, ratio: string}>  $splits
     */
    public function __construct(
        public string $exchange,
        public string $quoteCurrency,
        public array $dailyOhlc,
        public array $dividends,
        public array $splits,
    ) {}
}
