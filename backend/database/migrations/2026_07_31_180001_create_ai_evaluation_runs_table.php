<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A.3 / Phase 5 — evaluation harness run metadata (append-only).
 * Records Provider, Model, Model version, Prompt version, Evaluation version,
 * Timestamp, Cost, Run ID per Trystan's provider-neutral requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('model_version', 128)->nullable();
            $table->string('prompt_version', 128);
            $table->string('evaluation_version', 128);
            $table->string('benchmark_set_version', 128)->nullable();
            $table->string('run_type', 64); // manual | scheduled | triggered-by-change | smoke
            $table->string('initiated_by_type', 64); // personal_access_token | user
            $table->unsignedBigInteger('initiated_by_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->decimal('total_cost', 12, 6)->default(0);
            $table->string('status', 32); // running | completed | failed
            $table->string('trace_id', 64)->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only at schema level.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_runs');
    }
};
