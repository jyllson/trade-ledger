<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Models\AnalysisProfile;

/**
 * Read-only: the stored default profile's criteria, or the built-in default
 * when none is stored (D-048). Never writes — read models (e.g. the trader
 * comparison) stay free of writes; the default is stored by a migration.
 */
final class ResolveDefaultAnalysisProfile
{
    public function handle(): AnalysisProfileCriteria
    {
        $profile = AnalysisProfile::query()->where('is_default', true)->first();

        return $profile?->toCriteria() ?? AnalysisProfileCriteria::builtInDefault();
    }
}
