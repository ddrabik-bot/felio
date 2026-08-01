<?php

namespace App\Domain\MarketData;

enum AvailabilityStatus: string
{
    case Available = 'available';
    case NoData = 'no_data';
    case Unavailable = 'unavailable';
}
