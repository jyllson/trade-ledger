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
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trader_id')->constrained()->cascadeOnDelete();
            // First time this exact content was observed (UTC).
            $table->timestamp('captured_at');
            // Latest import that observed identical content — an unchanged
            // portfolio refreshes this instead of creating a new snapshot
            // (docs/DECISIONS.md D-038).
            $table->timestamp('last_confirmed_at');
            // Exact fractions in parts per billion (App\Analytics\ValueObjects\Percentage),
            // never floats. cash = eToro realizedCreditPct (D-037), null when
            // the payload did not carry it; invested = Σ position weights.
            $table->bigInteger('cash_weight_ppb')->nullable();
            $table->bigInteger('invested_weight_ppb');
            $table->unsignedInteger('position_count');
            // socialTrades entries are not modelled — only counted (D-037).
            $table->unsignedInteger('social_trades_count');
            // sha256 of the canonical normalized content (D-038).
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->index(['trader_id', 'captured_at'], 'pf_snap_trader_captured_idx');
            $table->index(['trader_id', 'source_hash'], 'pf_snap_trader_hash_idx');
        });

        Schema::table('traders', function (Blueprint $table) {
            $table->timestamp('portfolio_synced_at')->nullable();
            // available|private|not_found — App\Models\PerformanceVisibility.
            $table->string('portfolio_visibility', 16)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traders', function (Blueprint $table) {
            $table->dropColumn(['portfolio_synced_at', 'portfolio_visibility']);
        });

        Schema::dropIfExists('portfolio_snapshots');
    }
};
