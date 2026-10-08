<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Own eToro account snapshots (docs/DECISIONS.md D-051). Money in
     * integer USD cents (D-042), units/rates as DECIMAL(30,10) — never
     * floats. No raw payload is stored (D-017).
     */
    public function up(): void
    {
        Schema::create('account_snapshots', function (Blueprint $table) {
            $table->id();
            // demo|real — App\Etoro\EtoroEnvironment. Real sync is disabled
            // in code until Demo acceptance (PROJECT.md §20 M6).
            $table->string('environment', 8);
            // First time this exact content was observed (UTC).
            $table->timestamp('captured_at');
            // Latest sync that observed identical content (same contract as
            // portfolio_snapshots, D-038).
            $table->timestamp('last_confirmed_at');
            $table->bigInteger('credit_cents');
            // clientPortfolio.unrealizedPnL as received.
            $table->bigInteger('unrealized_pnl_cents');
            $table->bigInteger('bonus_credit_cents')->nullable();
            $table->unsignedInteger('account_currency_id')->nullable();
            // eToro "Calculate Total Invested" / "Calculate Equity" guides;
            // null (with a reason) when a term is missing from the payload.
            $table->bigInteger('invested_cents')->nullable();
            $table->bigInteger('equity_cents')->nullable();
            $table->string('equity_unavailable_reason', 32)->nullable();
            $table->unsignedInteger('position_count');
            $table->unsignedInteger('mirror_count');
            $table->unsignedInteger('mirror_position_count');
            $table->unsignedInteger('pending_order_count');
            // sha256 of the canonical normalized content.
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->index(['environment', 'captured_at'], 'acct_snap_env_captured_idx');
            $table->index(['environment', 'source_hash'], 'acct_snap_env_hash_idx');
        });

        Schema::create('account_mirrors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_snapshot_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('mirror_index');
            $table->string('external_mirror_id', 64);
            // Copied trader's CID; linked to traders.external_cid when that
            // trader is stored (no FK — the trader may never be imported).
            $table->string('parent_cid', 64);
            $table->string('parent_username', 64)->nullable();
            $table->bigInteger('initial_investment_cents')->nullable();
            $table->bigInteger('deposit_summary_cents')->nullable();
            $table->bigInteger('withdrawal_summary_cents')->nullable();
            $table->bigInteger('available_amount_cents')->nullable();
            $table->bigInteger('closed_positions_net_profit_cents')->nullable();
            // Σ of the mirror's open positions; null when unknown.
            $table->bigInteger('invested_cents')->nullable();
            $table->bigInteger('unrealized_pnl_cents')->nullable();
            $table->unsignedInteger('position_count')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->boolean('is_paused')->nullable();
            $table->boolean('pending_for_closure')->nullable();
            $table->integer('status_id')->nullable();
            $table->timestamps();

            $table->unique(['account_snapshot_id', 'mirror_index'], 'acct_mirror_snapshot_index_unique');
            $table->index('parent_cid', 'acct_mirror_parent_cid_idx');
        });

        Schema::create('account_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_snapshot_id')->constrained()->cascadeOnDelete();
            // Set only for positions listed inside a mirror.
            $table->foreignId('account_mirror_id')->nullable()->constrained()->cascadeOnDelete();
            // Ordinal across the snapshot: top-level positions first, then
            // each mirror's positions, in payload order.
            $table->unsignedInteger('position_index');
            $table->string('external_position_id', 64);
            // Payload mirrorID (null for the documented 0 sentinel).
            $table->string('external_mirror_id', 64)->nullable();
            $table->string('external_parent_position_id', 64)->nullable();
            $table->foreignId('instrument_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_instrument_id', 64);
            $table->boolean('is_buy');
            $table->bigInteger('amount_cents');
            $table->bigInteger('initial_amount_cents')->nullable();
            $table->decimal('units', 30, 10)->nullable();
            $table->decimal('open_rate', 30, 10)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->integer('leverage')->nullable();
            $table->bigInteger('pnl_cents')->nullable();
            $table->timestamps();

            $table->unique(['account_snapshot_id', 'position_index'], 'acct_pos_snapshot_index_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_positions');
        Schema::dropIfExists('account_mirrors');
        Schema::dropIfExists('account_snapshots');
    }
};
