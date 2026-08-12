<?php

namespace App\Domain\Portfolio;

enum AssetClass: string
{
    case Equity = 'Equity';
    case ExchangeTradedFund = 'Exchange-traded fund';
    case FixedIncome = 'Fixed income';
    case Cash = 'Cash';
    case Unclassified = 'Unclassified';
}
