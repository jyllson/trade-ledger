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
        Schema::create('instruments', function (Blueprint $table) {
            $table->id();
            // eToro instrumentId exactly as normalized by the portfolio
            // mapper (App\Etoro\Mappers\Support\Identifiers) — a string, so
            // the persistence boundary never reinterprets it.
            $table->string('external_instrument_id', 64)->unique();
            // Enrichment from GET /api/v1/market-data/instruments (D-037);
            // every column is best-effort and stays null until metadata has
            // been fetched successfully (docs/DECISIONS.md D-038).
            $table->string('symbol')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('instrument_type_id')->nullable();
            // eToro instrument type description (Stocks, ETF, Crypto, ...) —
            // the "asset class" used by concentration metrics (D-037).
            $table->string('asset_class', 64)->nullable();
            $table->unsignedInteger('exchange_id')->nullable();
            $table->unsignedInteger('stocks_industry_id')->nullable();
            // Last successful metadata fetch; null = never enriched.
            $table->timestamp('metadata_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instruments');
    }
};
