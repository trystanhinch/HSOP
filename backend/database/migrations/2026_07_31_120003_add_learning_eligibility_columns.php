<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 1 — learning eligibility state machine columns.
 * Default + backfill: pending_review. No auto-verified / auto-excluded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('estimate_outcomes')) {
            Schema::table('estimate_outcomes', function (Blueprint $table) {
                if (! Schema::hasColumn('estimate_outcomes', 'learning_eligibility_status')) {
                    $table->string('learning_eligibility_status', 32)
                        ->default('pending_review')
                        ->after('environmental_context')
                        ->index();
                }
                if (! Schema::hasColumn('estimate_outcomes', 'learning_eligibility_reason')) {
                    $table->text('learning_eligibility_reason')->nullable()->after('learning_eligibility_status');
                }
                if (! Schema::hasColumn('estimate_outcomes', 'learning_eligibility_reviewed_by')) {
                    $table->foreignId('learning_eligibility_reviewed_by')
                        ->nullable()
                        ->after('learning_eligibility_reason')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('estimate_outcomes', 'learning_eligibility_reviewed_at')) {
                    $table->timestamp('learning_eligibility_reviewed_at')
                        ->nullable()
                        ->after('learning_eligibility_reviewed_by');
                }
            });

            DB::table('estimate_outcomes')
                ->whereNull('learning_eligibility_status')
                ->orWhere('learning_eligibility_status', '')
                ->update(['learning_eligibility_status' => 'pending_review']);
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (! Schema::hasColumn('jobs', 'learning_eligibility_status')) {
                    $table->string('learning_eligibility_status', 32)
                        ->default('pending_review')
                        ->after('materials_used')
                        ->index();
                }
                if (! Schema::hasColumn('jobs', 'learning_eligibility_reason')) {
                    $table->text('learning_eligibility_reason')->nullable()->after('learning_eligibility_status');
                }
                if (! Schema::hasColumn('jobs', 'learning_eligibility_reviewed_by')) {
                    $table->foreignId('learning_eligibility_reviewed_by')
                        ->nullable()
                        ->after('learning_eligibility_reason')
                        ->constrained('users')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('jobs', 'learning_eligibility_reviewed_at')) {
                    $table->timestamp('learning_eligibility_reviewed_at')
                        ->nullable()
                        ->after('learning_eligibility_reviewed_by');
                }
            });

            DB::table('jobs')
                ->whereNull('learning_eligibility_status')
                ->orWhere('learning_eligibility_status', '')
                ->update(['learning_eligibility_status' => 'pending_review']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('estimate_outcomes')) {
            Schema::table('estimate_outcomes', function (Blueprint $table) {
                if (Schema::hasColumn('estimate_outcomes', 'learning_eligibility_reviewed_by')) {
                    $table->dropConstrainedForeignId('learning_eligibility_reviewed_by');
                }
                foreach (['learning_eligibility_reviewed_at', 'learning_eligibility_reason', 'learning_eligibility_status'] as $col) {
                    if (Schema::hasColumn('estimate_outcomes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (Schema::hasColumn('jobs', 'learning_eligibility_reviewed_by')) {
                    $table->dropConstrainedForeignId('learning_eligibility_reviewed_by');
                }
                foreach (['learning_eligibility_reviewed_at', 'learning_eligibility_reason', 'learning_eligibility_status'] as $col) {
                    if (Schema::hasColumn('jobs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
