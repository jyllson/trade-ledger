<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Why a single ranking entry was rejected as a controlled trader identity
 * conflict. Only the two currently-proven scenarios ImportRankingPage
 * actually distinguishes — see App\Application\Imports\ImportRankingPage.
 */
enum ImportRunFailureReason: string
{
    case IdentityConflictWithinPage = 'identity_conflict_within_page';
    case IdentityConflictWithExistingTrader = 'identity_conflict_with_existing_trader';
}
