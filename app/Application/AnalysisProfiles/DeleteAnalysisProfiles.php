<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Models\AnalysisProfile;
use Illuminate\Support\Facades\DB;

/**
 * Deletes non-default analysis profiles (D-048). The `is_default` of an
 * already loaded model may be stale — a concurrent "Make default" can
 * promote it after it was read — so the CURRENT default is checked inside
 * one transaction that locks every profile row, in the same order as
 * MakeAnalysisProfileDefault, right before the DELETE; the DELETE itself
 * also excludes the default. All or nothing: when the default is among
 * the given profiles, none is deleted.
 */
final class DeleteAnalysisProfiles
{
    /**
     * @param  iterable<AnalysisProfile>  $profiles
     * @return int the number of deleted profiles (already deleted ones are skipped)
     *
     * @throws DefaultAnalysisProfileCannotBeDeleted
     */
    public function handle(iterable $profiles): int
    {
        $ids = [];

        foreach ($profiles as $profile) {
            $ids[] = $profile->getKey();
        }

        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            AnalysisProfile::query()->lockForUpdate()->get(['id']);

            if (AnalysisProfile::query()->whereKey($ids)->where('is_default', true)->exists()) {
                throw new DefaultAnalysisProfileCannotBeDeleted;
            }

            return AnalysisProfile::query()->whereKey($ids)->where('is_default', false)->delete();
        });
    }
}
