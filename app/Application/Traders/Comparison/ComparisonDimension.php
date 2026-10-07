<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

/**
 * The independent comparison dimensions of PROJECT.md §14. They are never
 * combined into an overall score (§14 "No overall score").
 */
enum ComparisonDimension: string
{
    case Performance = 'performance';
    case Risk = 'risk';
    case Consistency = 'consistency';
    case Copyability = 'copyability';
    case DataQuality = 'data_quality';
}
