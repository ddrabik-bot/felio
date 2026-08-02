<?php

namespace App\Domain\Valuation;

/**
 * Stale FX rates are rejected by default. Accepting them retains the stale
 * result state and diagnostic so consumers cannot mistake it for fresh data.
 */
enum StaleFxRatePolicy: string
{
    case Reject = 'reject';
    case Accept = 'accept';
}
