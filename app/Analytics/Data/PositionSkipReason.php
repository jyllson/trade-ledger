<?php

declare(strict_types=1);

namespace App\Analytics\Data;

enum PositionSkipReason: string
{
    case BelowMinimum = 'below_minimum';
    case ZeroWeight = 'zero_weight';
    case NegativeWeight = 'negative_weight';
}
