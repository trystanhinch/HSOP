<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A.3 / Phase 5 — evaluation findings (append-only).
 * Distinguishes observed_fact / inference / recommendation (EVAL-11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluation_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evaluation_run_id')->index();
            $table->string('subject_type', 64); // ai_conversation_log | ai_action_log
            $table->unsignedBigInteger('subject_id')->index();
            $table->string('dimension', 64);
            $table->decimal('score', 8, 2);
            $table->decimal('max_score', 8, 2)->default(5);
            $table->text('critique')->nullable();
            $table->string('statement_kind', 32); // observed_fact | inference | recommendation
            $table->string('evidence_reference', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('evaluation_run_id')
                ->references('id')
                ->on('ai_evaluation_runs')
                ->cascadeOnDelete();
            // No updated_at — append-only at schema level.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_findings');
    }
};
