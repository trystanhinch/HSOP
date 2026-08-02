<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 3 — recommend vs finalize authority + delegation flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'can_finalize_learning_eligibility')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_finalize_learning_eligibility')
                    ->default(false)
                    ->after('is_developer');
            });
        }

        $this->addEligibilityAuthorityColumns('estimate_outcomes', 'learning_eligibility_reviewed_at');
        $this->addEligibilityAuthorityColumns('jobs', 'learning_eligibility_reviewed_at');
    }

    private function addEligibilityAuthorityColumns(string $table, string $after): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $after) {
            if (! Schema::hasColumn($table, 'learning_recommended_status')) {
                $blueprint->string('learning_recommended_status', 32)->nullable()->after($after);
            }
            if (! Schema::hasColumn($table, 'learning_recommended_by')) {
                $blueprint->foreignId('learning_recommended_by')
                    ->nullable()
                    ->after('learning_recommended_status')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn($table, 'learning_recommended_at')) {
                $blueprint->timestamp('learning_recommended_at')->nullable()->after('learning_recommended_by');
            }
            if (! Schema::hasColumn($table, 'learning_recommendation_reason')) {
                $blueprint->text('learning_recommendation_reason')->nullable()->after('learning_recommended_at');
            }
            if (! Schema::hasColumn($table, 'learning_recommendation_missing_actuals')) {
                $blueprint->json('learning_recommendation_missing_actuals')->nullable()->after('learning_recommendation_reason');
            }
            if (! Schema::hasColumn($table, 'learning_approved_by')) {
                $blueprint->foreignId('learning_approved_by')
                    ->nullable()
                    ->after('learning_recommendation_missing_actuals')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn($table, 'learning_approved_at')) {
                $blueprint->timestamp('learning_approved_at')->nullable()->after('learning_approved_by');
            }
        });
    }

    public function down(): void
    {
        foreach (['estimate_outcomes', 'jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'learning_approved_by')) {
                    $blueprint->dropConstrainedForeignId('learning_approved_by');
                }
                if (Schema::hasColumn($table, 'learning_recommended_by')) {
                    $blueprint->dropConstrainedForeignId('learning_recommended_by');
                }
                foreach ([
                    'learning_approved_at',
                    'learning_recommendation_missing_actuals',
                    'learning_recommendation_reason',
                    'learning_recommended_at',
                    'learning_recommended_status',
                ] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $blueprint->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'can_finalize_learning_eligibility')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('can_finalize_learning_eligibility');
            });
        }
    }
};
