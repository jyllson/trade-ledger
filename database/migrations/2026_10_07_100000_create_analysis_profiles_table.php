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
        Schema::create('analysis_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique('analysis_profiles_name_unique');
            // Money in integer USD cents, fractions in ppb (1.0 = 10⁹), like
            // copy_simulations (D-042) — never floats or DECIMAL (D-048).
            $table->unsignedBigInteger('budget_cents');
            $table->bigInteger('target_coverage_ppb')->default(950_000_000);
            // Restrictive criteria: null = not applied (D-048).
            $table->bigInteger('maximum_drawdown_ppb')->nullable();
            $table->unsignedTinyInteger('maximum_risk_score')->nullable();
            $table->bigInteger('maximum_single_position_ppb')->nullable();
            $table->unsignedSmallInteger('minimum_history_months')->nullable();
            $table->bigInteger('minimum_positive_months_ppb')->nullable();
            $table->bigInteger('maximum_allocation_per_trader_ppb')->nullable();
            $table->boolean('is_default')->default(false);
            // 1 for the default profile, NULL otherwise: a unique index over
            // it allows any number of non-default rows but at most one
            // default (MySQL has no partial indexes; D-048).
            $table->unsignedTinyInteger('default_marker')->nullable()->storedAs('case when is_default = 1 then 1 end');
            $table->timestamps();

            $table->unique('default_marker', 'analysis_profiles_one_default_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_profiles');
    }
};
