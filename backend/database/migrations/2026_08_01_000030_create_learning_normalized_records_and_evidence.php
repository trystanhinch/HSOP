<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 4 — Learning AI normalized records + append-only evidence entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('learning_normalized_records')) {
            Schema::create('learning_normalized_records', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 64); // job|estimate_outcome|lead
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('job_id')->nullable()->index();
                $table->unsignedBigInteger('estimate_outcome_id')->nullable()->index();
                $table->unsignedBigInteger('lead_id')->nullable()->index();
                $table->string('learning_eligibility_status', 32)->default('pending_review')->index();
                $table->json('extracted_fields')->nullable();
                $table->json('provenance')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->json('warnings')->nullable();
                $table->json('missing_data_flags')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('learning_evidence_entries')) {
            Schema::create('learning_evidence_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('learning_normalized_record_id')
                    ->constrained('learning_normalized_records')
                    ->cascadeOnDelete();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->json('source_references')->nullable();
                $table->json('warnings')->nullable();
                $table->json('missing_data_flags')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_evidence_entries');
        Schema::dropIfExists('learning_normalized_records');
    }
};
