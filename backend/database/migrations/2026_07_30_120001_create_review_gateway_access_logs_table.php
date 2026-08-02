<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A Phase 1 — append-only review gateway access ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_gateway_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->string('token_name')->nullable();
            $table->string('ability')->nullable();
            $table->string('tool')->nullable();
            $table->string('http_method', 16);
            $table->string('path', 512);
            $table->json('parameters')->nullable();
            $table->unsignedInteger('response_record_count')->nullable();
            $table->string('outcome', 32); // success | denied | error
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('trace_id', 64)->index();
            $table->string('denial_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — append-only at schema level too.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_gateway_access_logs');
    }
};
