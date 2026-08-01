<?php

namespace App\Domain\MarketData;

interface YahooFinanceGateway
{
    /**
     * Fetches daily history with auto_adjust=False and actions=True from the
     * yfinance boundary. The adapter owns all result classification.
     *
     * @throws YahooFinanceGatewayException
     */
    public function fetch(string $symbol): YahooFinancePayload;
}
