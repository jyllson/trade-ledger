<?php

declare(strict_types=1);

namespace App\Application\Traders\Comparison;

enum TraderComparisonRejectionReason: string
{
    case TooFewTraders = 'too_few_traders';
    case TooManyTraders = 'too_many_traders';
    case DuplicateTrader = 'duplicate_trader';
    case UnknownTrader = 'unknown_trader';
}
