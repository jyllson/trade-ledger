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
            // Self-referencing: identifies the immediately retried run for a
            // manual eToro discovery retry — the run directly before this
            // one in a retry chain, never the chain's root. Nullable so
            // every ordinary (non-retry) discovery/profile/fixture run
            // remains unaffected. Distinct from parent_import_run_id, which
            // links a per-page `rankings` run back to the aggregate
            // `rankings_discovery` run that spawned it — that semantic is
            // unchanged. constrained() already registers this column as an
            // index for the foreign key — no separate ->index() call is
            // added for it.
            $table->foreignId('retry_of_import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retry_of_import_run_id');
        });
    }
};
