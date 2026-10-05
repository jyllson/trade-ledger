<?php

namespace App\Etoro;

/**
 * Path granularity of the documented v2 investor gain time-series endpoint
 * (`GET /api/v2/portfolios/{username}/gain/{granularity}`, OpenAPI
 * `getGainHistory`). Values match the documented enum exactly.
 */
enum GainGranularity: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
