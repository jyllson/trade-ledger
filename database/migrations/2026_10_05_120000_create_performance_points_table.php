<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('performance_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trader_id')->constrained()->cascadeOnDelete();
            // daily|monthly|yearly — App\Analytics\Data\ReturnPeriodGranularity.
            $table->string('granularity', 16);
            // The API's own period date (UTC). For a partial first period
            // this is NOT the 1st of the month — partial/in-progress status
            // is derived from this date and synced_at, never stored
            // (docs/DECISIONS.md D-032/D-034).
            $table->date('period_start');
            // Period return as an exact signed decimal fraction in parts per
            // billion (App\Analytics\ValueObjects\Percentage): 0.0123 = 12_300_000.
            // Never a float.
            $table->bigInteger('gain_ppb');
            // Which upstream series produced the row, e.g. etoro_v2_gain.
            $table->string('source', 32);
            // When the stored series was last confirmed by a successful sync.
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(
                ['trader_id', 'granularity', 'period_start', 'source'],
                'perf_points_trader_gran_period_source_unique',
            );
        });

        Schema::table('traders', function (Blueprint $table) {
            $table->timestamp('performance_synced_at')->nullable();
            // available|private|not_found — App\Models\PerformanceVisibility.
            $table->string('performance_visibility', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traders', function (Blueprint $table) {
            $table->dropColumn(['performance_synced_at', 'performance_visibility']);
        });

        Schema::dropIfExists('performance_points');
    }
};
