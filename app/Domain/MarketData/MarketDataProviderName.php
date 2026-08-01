<?php

namespace App\Domain\MarketData;

enum MarketDataProviderName: string
{
    case YahooFinance = 'yahoo_finance';
}
