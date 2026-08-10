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
        Schema::create('traders', function (Blueprint $table) {
            $table->id();
            // eToro's own trader identifier, stored verbatim as received from
            // RankingEntry::$cid (already normalized to string upstream —
            // see App\Etoro\Mappers\Support\Identifiers::normalize()) and
            // never reinterpreted as numeric here. Named external_cid, not
            // cid, to keep the vendor-specific identifier explicit at the
            // persistence boundary. This is the stable trader identity;
            // username is not, since eToro allows username changes. A future
            // importer must upsert on external_cid, not username, and must
            // fail closed (not silently rename or duplicate) on any
            // cid/username identity conflict.
            $table->string('external_cid')->unique();
            $table->string('username')->unique();
            $table->string('ranking_type');
            $table->string('ranking_sub_type');
            $table->unsignedInteger('copiers_count')->default(0);
            $table->string('status')->default('candidate');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('traders');
    }
};
