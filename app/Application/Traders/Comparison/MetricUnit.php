<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * Type of a ComparisonMetric value.
 *
 * - Fraction: Percentage (decimal fraction in ppb, 0.25 = 25%).
 * - Money: Money (USD cents).
 * - Count: int (number of periods / positions / runs).
 * - Multiple: numeric-string with 9 decimals (e.g. weighted leverage 1.5x).
 * - Timestamp: DateTimeImmutable in UTC (displayed in Europe/Malta, D-046).
 * - Visibility: PerformanceVisibility value string.
 * - Flag: bool.
 */
enum MetricUnit: string
{
    case Fraction = 'fraction';
    case Money = 'money';
    case Count = 'count';
    case Multiple = 'multiple';
    case Timestamp = 'timestamp';
    case Visibility = 'visibility';
    case Flag = 'flag';
}
