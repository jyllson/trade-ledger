<?php

declare(strict_types=1);

namespace App\Application\AnalysisProfiles;

use App\Models\AnalysisProfile;
use Illuminate\Support\Facades\DB;

/**
 * Atomically moves the default flag to the given profile (D-048): in one
 * transaction, with every profile row locked, the previous default is
 * cleared BEFORE the new one is set (the unique index over
 * `default_marker` admits at most one default at any statement), so no
 * reader ever sees zero or two defaults after commit.
 */
final class MakeAnalysisProfileDefault
{
    public function handle(AnalysisProfile $profile): AnalysisProfile
    {
        DB::transaction(function () use ($profile): void {
            AnalysisProfile::query()->lockForUpdate()->get(['id']);

            // A profile deleted meanwhile throws here — before the current
            // default is cleared — so the table never ends without one.
            AnalysisProfile::query()->findOrFail($profile->getKey());

            AnalysisProfile::query()
                ->where('is_default', true)
                ->whereKeyNot($profile->getKey())
                ->update(['is_default' => false]);

            AnalysisProfile::query()
                ->whereKey($profile->getKey())
                ->update(['is_default' => true]);
        });

        return $profile->refresh();
    }
}
