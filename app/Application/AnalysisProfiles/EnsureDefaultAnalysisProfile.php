<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Models\AnalysisProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Guarantees that exactly one stored default profile exists (D-048) and
 * returns it. Idempotent:
 *
 * - a default exists → returned unchanged;
 * - profiles exist but none is the default → the oldest (lowest id) becomes it;
 * - no profile exists → the built-in default is stored ("Default", $500,
 *   95%, no restrictive criterion).
 *
 * A concurrent caller that wins the race trips the unique index over
 * `default_marker`; the loser re-reads the winner's default.
 */
final class EnsureDefaultAnalysisProfile
{
    public function handle(): AnalysisProfile
    {
        $existing = $this->currentDefault();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function (): AnalysisProfile {
                $oldest = AnalysisProfile::query()->orderBy('id')->lockForUpdate()->first();

                if ($oldest !== null) {
                    $oldest->forceFill(['is_default' => true])->save();

                    return $oldest;
                }

                $defaults = AnalysisProfileCriteria::builtInDefault();
                $profile = new AnalysisProfile([
                    // Only reached on an empty table, so the name is free.
                    'name' => $defaults->name,
                    'budget_cents' => $defaults->budget->cents(),
                    'target_coverage_ppb' => $defaults->targetCoverage->partsPerBillion(),
                ]);
                $profile->forceFill(['is_default' => true])->save();

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->currentDefault() ?? throw new LogicException('No default analysis profile after a unique-constraint race.');
        }
    }

    private function currentDefault(): ?AnalysisProfile
    {
        return AnalysisProfile::query()->where('is_default', true)->first();
    }
}
