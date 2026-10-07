<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stores the built-in default analysis profile (D-048), so that read
     * pages never have to write it. Idempotent: an existing default is
     * kept; profiles without a default get the oldest (lowest id) promoted;
     * only an empty table receives the built-in "Default" ($500, 95%, no
     * restrictive criterion). The values are frozen here on purpose — they
     * equal AnalysisProfileSettings at the time of writing.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('analysis_profiles')->orderBy('id')->lockForUpdate()->pluck('id');

            if (DB::table('analysis_profiles')->where('is_default', true)->exists()) {
                return;
            }

            $oldest = $ids->first();

            if ($oldest !== null) {
                DB::table('analysis_profiles')->where('id', $oldest)->update(['is_default' => true]);

                return;
            }

            $now = now();

            DB::table('analysis_profiles')->insert([
                'name' => 'Default',
                'budget_cents' => 50_000,
                'target_coverage_ppb' => 950_000_000,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * Nothing to undo: the stored default is user data from here on (it may
     * have been edited), and dropping the table is the previous migration's
     * job.
     */
    public function down(): void
    {
        //
    }
};
