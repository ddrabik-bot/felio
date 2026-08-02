<?php

namespace App\Domain\Valuation;

enum ValuationAvailability: string
{
    case Available = 'available';
    case Stale = 'stale';
    case Unavailable = 'unavailable';
}
