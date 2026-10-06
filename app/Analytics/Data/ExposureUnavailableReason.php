<?php

declare(strict_types=1);

namespace App\Analytics\Data;

enum ExposureUnavailableReason: string
{
    /** No position with a known, non-negative weight above zero (empty or cash-only portfolio). */
    case NoInvestedWeight = 'no_invested_weight';

    /** Invested weight exists, but none of it carries the needed classification/leverage. */
    case NoData = 'no_data';

    /** The source has no verified classification for this dimension (D-041: sector). */
    case ClassificationNotSupported = 'classification_not_supported';
}
