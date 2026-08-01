<?php

namespace App\Domain\MarketData;

enum MarketDataErrorCategory: string
{
    case Transport = 'transport';
    case RateLimited = 'rate_limited';
    case InstrumentNotFound = 'instrument_not_found';
    case InvalidResponse = 'invalid_response';
}
