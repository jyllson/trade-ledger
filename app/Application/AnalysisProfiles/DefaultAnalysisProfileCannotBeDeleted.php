<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use LogicException;

/**
 * Exactly one analysis profile is the default (D-048): it can only stop
 * being the default when another profile is made the default.
 */
final class DefaultAnalysisProfileCannotBeDeleted extends LogicException
{
    public function __construct()
    {
        parent::__construct('The default analysis profile cannot be deleted; make another profile the default first.');
    }
}
