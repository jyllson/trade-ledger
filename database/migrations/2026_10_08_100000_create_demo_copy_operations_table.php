<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log of every demo copy-trading attempt (D-050): one row per
     * pre-check, start, adjust, close and outcome poll. Never stores keys,
     * headers or full payloads — only sanitized, allow-listed fields.
     */
    public function up(): void
    {
        Schema::create('demo_copy_operations', function (Blueprint $table) {
            $table->id();
            // Audit rows outlive the trader and the user who started them.
            $table->foreignId('trader_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // start/adjust → their pre-check; poll → the start/adjust it polls.
            $table->foreignId('parent_operation_id')->nullable()->constrained('demo_copy_operations')->nullOnDelete();
            $table->string('type', 16);
            $table->string('status', 16);
            // Snapshot of the copied investor as sent to eToro.
            $table->unsignedBigInteger('parent_cid')->nullable();
            $table->string('trader_username')->nullable();
            // Signed integer USD cents (negative = remove funds), like
            // copy_simulations — never floats or DECIMAL.
            $table->bigInteger('amount_cents')->nullable();
            $table->string('reference_id', 35)->nullable();
            $table->uuid('request_id')->nullable();
            $table->uuid('client_request_id')->nullable();
            $table->unsignedBigInteger('mirror_id')->nullable();
            $table->string('unregister_type', 16)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('transport_outcome', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('response')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['trader_id', 'type', 'status'], 'demo_copy_ops_trader_type_status_idx');
            $table->index('reference_id', 'demo_copy_ops_reference_idx');
            $table->index('status', 'demo_copy_ops_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('demo_copy_operations');
    }
};
