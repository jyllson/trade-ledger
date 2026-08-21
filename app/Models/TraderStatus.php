<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Trader triage state assigned locally after an eToro ranking import.
 * Independent of any eToro-reported field.
 */
enum TraderStatus: string
{
    case Candidate = 'candidate';
    case Watched = 'watched';
    case Ignored = 'ignored';
}
