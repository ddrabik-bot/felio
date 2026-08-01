<?php

namespace App\Domain\Fx;

enum FxRateAvailability: string
{
    case Available = 'available';
    case Stale = 'stale';
    case Unavailable = 'unavailable';
}
