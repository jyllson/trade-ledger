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
        Schema::table('import_runs', function (Blueprint $table) {
            // Self-referencing: links a per-page `rankings` ImportRun back
            // to the `rankings_discovery` aggregate run that spawned it.
            // Nullable so every existing/fixture-only single-page caller
            // (ImportRankingPageFromFixture, and any direct
            // ImportRankingPage::handle() call with no third argument)
            // remains unaffected. constrained() already registers this
            // column as an index for the foreign key — no separate
            // ->index() call is added for it.
            $table->foreignId('parent_import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_import_run_id');
            $table->dropIndex(['type']);
        });
    }
};
