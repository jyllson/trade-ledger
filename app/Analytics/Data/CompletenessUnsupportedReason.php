<?php

declare(strict_types=1);

namespace App\Analytics\Data;

/**
 * Why a §13.8 check is "not supported by this application" (D-047): a
 * limitation of the application, the same for every trader — never a gap
 * in the trader's data, so it is excluded from the completeness score.
 */
enum CompletenessUnsupportedReason: string
{
    /** The application has no integration that collects this source. */
    case NotCollectedByApplication = 'not_collected_by_application';
}
