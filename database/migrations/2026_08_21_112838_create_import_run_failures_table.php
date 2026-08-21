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
        Schema::create('import_run_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_run_id')->constrained()->cascadeOnDelete();
            // 1-based position of the RankingEntry within its RankingPage,
            // preserving the original server-returned order.
            $table->unsignedInteger('row_number');
            // The attempted eToro identity — never the existing conflicting
            // Trader row's own identity, and never a raw payload/exception
            // message/credential.
            $table->string('external_cid');
            $table->string('username');
            $table->string('reason');
            $table->timestamps();
            // Also serves as the lookup index for import_run_id — no
            // separate ->index() call is added for it.
            $table->unique(['import_run_id', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_run_failures');
    }
};
