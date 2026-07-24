<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 — Learning Centre data foundation (capture only).
 * New physical storage only for fields that do not already exist elsewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (! Schema::hasColumn('jobs', 'actual_labour_hours')) {
                    $table->decimal('actual_labour_hours', 8, 2)->nullable()->after('scope_of_work');
                }
                if (! Schema::hasColumn('jobs', 'materials_used')) {
                    // Actual materials used on the job (structured list)
                    $table->json('materials_used')->nullable()->after('actual_labour_hours');
                }
            });
        }

        if (! Schema::hasTable('pricing_override_logs')) {
            Schema::create('pricing_override_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject_type', 40); // pricing_rule | lead_estimate
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->string('override_kind', 60); // rule_edit | estimate_manual_adjust
                $table->json('before_json')->nullable();
                $table->json('after_json')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id']);
                $table->index(['lead_id', 'job_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_override_logs');

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (Schema::hasColumn('jobs', 'materials_used')) {
                    $table->dropColumn('materials_used');
                }
                if (Schema::hasColumn('jobs', 'actual_labour_hours')) {
                    $table->dropColumn('actual_labour_hours');
                }
            });
        }
    }
};
