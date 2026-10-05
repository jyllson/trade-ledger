<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Length of one period in a ReturnSeries. Every performance result carries
 * its granularity so a monthly-derived figure is never presented as daily
 * (PROJECT.md §13.3).
 */
enum ReturnPeriodGranularity: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
