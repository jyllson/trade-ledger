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
        Schema::create('copy_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trader_id')->constrained()->cascadeOnDelete();
            // Always computed from a stored snapshot, never live (D-042).
            $table->foreignId('portfolio_snapshot_id')->constrained()->cascadeOnDelete();
            // Inputs. Money in integer USD cents (App\Analytics\ValueObjects\Money),
            // never floats or DECIMAL; analysis_profile_id is deliberately
            // absent until analysis_profiles exists (D-042).
            $table->unsignedBigInteger('copy_amount_cents');
            $table->unsignedBigInteger('minimum_position_amount_cents')->default(100);
            $table->unsignedBigInteger('platform_minimum_copy_amount_cents')->default(20_000);
            // Exact fraction in ppb, relative to the visible positive
            // position weight (D-022); null when no target was requested.
            $table->bigInteger('target_coverage_ppb')->nullable();
            // Summary of `result` for querying. Weights are exact fractions
            // of the WHOLE portfolio in ppb, like portfolio_positions (D-038).
            $table->unsignedInteger('eligible_positions_count');
            $table->unsignedInteger('skipped_positions_count');
            $table->bigInteger('eligible_weight_ppb');
            $table->bigInteger('skipped_weight_ppb');
            $table->bigInteger('cash_weight_ppb')->nullable();
            // Null when no target was requested or no positive weight exists.
            $table->unsignedBigInteger('minimum_target_amount_cents')->nullable();
            $table->string('methodology_version', 32);
            $table->json('result');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index(['trader_id', 'calculated_at'], 'copy_sim_trader_calc_idx');
            $table->index(['portfolio_snapshot_id', 'methodology_version'], 'copy_sim_snap_method_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('copy_simulations');
    }
};
