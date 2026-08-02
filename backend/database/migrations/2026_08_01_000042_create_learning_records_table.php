<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 5 — canonical assembled learning_records (derived, versioned).
 * Distinct from Phase 4 learning_normalized_records (AI draft workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_records')) {
            return;
        }

        Schema::create('learning_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('record_group_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true)->index();

            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('quote_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('current_estimate_outcome_id')->nullable()->index();

            // Pointer into Phase 3 eligibility — NOT an independent status field
            $table->string('eligibility_source_type', 32); // job|estimate_outcome
            $table->unsignedBigInteger('eligibility_source_id');
            $table->string('eligibility_status_snapshot', 32)->nullable(); // mirror at assembly time only

            $table->json('payload'); // assembled field values (derived view)
            $table->json('provenance'); // keyed by field name
            $table->json('links')->nullable(); // related IDs: photos, messages, overrides, etc.
            $table->json('missing_sources')->nullable(); // which DAT-04 links were absent

            $table->timestamp('assembled_at');
            $table->timestamps();

            $table->unique(['record_group_id', 'version'], 'learning_records_group_version_uq');
            $table->index(['job_id', 'is_current'], 'learning_records_job_current_idx');
            $table->index(['eligibility_source_type', 'eligibility_source_id'], 'learning_records_elig_src_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_records');
    }
};
