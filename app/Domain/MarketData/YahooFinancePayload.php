<?php

namespace App\Domain\MarketData;

final readonly class YahooFinancePayload
{
    /**
     * The OHLC values are deliberately unadjusted provider values. Felio must make
     * a later, explicit normalization decision rather than treating them as adjusted.
     *
     * @param  list<DailyOhlc>  $dailyOhlc
     * @param  list<array{date: string, amount: string, provider_event_id?: string}>  $dividends
     * @param  list<array{date: string, ratio: string, provider_event_id?: string}>  $splits
     */
    public function __construct(
        public string $exchange,
        public string $quoteCurrency,
        public array $dailyOhlc,
        public array $dividends,
        public array $splits,
        public string $retrievedAt,
        public string $sourceTimezone,
        public string $providerVersion,
    ) {}
}
