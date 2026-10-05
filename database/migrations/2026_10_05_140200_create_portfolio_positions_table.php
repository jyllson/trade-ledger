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
        Schema::create('portfolio_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_snapshot_id')->constrained()->cascadeOnDelete();
            // Original order in the payload. Duplicate position ids are kept
            // as received (the coverage calculator reports them as a data
            // quality warning), so the ordinal — not the id — is unique.
            $table->unsignedInteger('position_index');
            $table->string('external_position_id', 64);
            $table->foreignId('instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_instrument_id', 64);
            // Exact fraction of the portfolio in ppb (investmentPct / 100).
            $table->bigInteger('weight_ppb');
            $table->timestamp('opened_at')->nullable();
            $table->boolean('is_buy')->nullable();
            $table->integer('leverage')->nullable();
            $table->decimal('take_profit_rate', 30, 10)->nullable();
            $table->decimal('stop_loss_rate', 30, 10)->nullable();
            $table->boolean('trailing_stop_loss')->nullable();
            $table->timestamps();

            $table->unique(['portfolio_snapshot_id', 'position_index'], 'pf_pos_snapshot_index_unique');
            $table->index('external_instrument_id', 'pf_pos_ext_instrument_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolio_positions');
    }
};
