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
        Schema::table('traders', function (Blueprint $table) {
            // The six currently-observed App\Etoro\Data\TraderProfile
            // fields, all nullable — a ranking candidate may never have
            // had its profile synced. profile_gcid is stored verbatim as a
            // plain observed attribute: it carries no unique constraint,
            // no index, and no identity semantics — it is never compared
            // against, or used to derive, external_cid. See
            // App\Application\Traders\LookupEtoroTraderProfile.
            $table->string('profile_gcid')->nullable();
            $table->boolean('profile_is_popular_investor')->nullable();
            $table->boolean('profile_is_verified')->nullable();
            $table->integer('profile_country_code')->nullable();
            $table->string('profile_language_iso_code')->nullable();
            $table->timestamp('profile_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('traders', function (Blueprint $table) {
            $table->dropColumn([
                'profile_gcid',
                'profile_is_popular_investor',
                'profile_is_verified',
                'profile_country_code',
                'profile_language_iso_code',
                'profile_synced_at',
            ]);
        });
    }
};
