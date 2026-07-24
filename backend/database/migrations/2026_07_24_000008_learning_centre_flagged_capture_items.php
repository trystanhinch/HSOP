<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 Learning Centre — flagged capture items (data only, no AI learning):
 * 1) ai_conversation_logs (full turns + retention via settings)
 * 2) estimate_outcomes.environmental_context (reserved; intentionally unpopulated — no weather API)
 * 3) contractor_performance_events (raw events, not rollups)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_conversation_logs')) {
            Schema::create('ai_conversation_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('intake_session_id')->index();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->unsignedInteger('turn_number');
                $table->string('role', 20); // user | assistant | system | tool
                $table->longText('content')->nullable();
                $table->json('tool_calls')->nullable();
                $table->json('tool_results')->nullable();
                $table->string('ai_provider', 40)->nullable();
                $table->string('ai_model', 80)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['intake_session_id', 'turn_number'], 'ai_conv_session_turn_idx');
                $table->index(['lead_id', 'created_at'], 'ai_conv_lead_created_idx');
                $table->index('created_at', 'ai_conv_created_idx');
            });

            try {
                Schema::table('ai_conversation_logs', function (Blueprint $table) {
                    $table->foreign('intake_session_id')->references('id')->on('intake_sessions')->cascadeOnDelete();
                });
            } catch (\Throwable) {
                // intake_sessions may use engine quirks in some envs
            }
        }

        if (Schema::hasTable('estimate_outcomes') && ! Schema::hasColumn('estimate_outcomes', 'environmental_context')) {
            Schema::table('estimate_outcomes', function (Blueprint $table) {
                // Reserved for future weather/env data. Intentionally left null —
                // Trystan decided to hold off on weather API integration for now.
                $table->json('environmental_context')->nullable()->after('embedding_vector');
            });
        }

        if (! Schema::hasTable('contractor_performance_events')) {
            Schema::create('contractor_performance_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contractor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->string('event_type', 40);
                $table->json('event_data')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['contractor_id', 'event_type', 'occurred_at'], 'cpe_contractor_type_at_idx');
                $table->index(['job_id', 'event_type'], 'cpe_job_type_idx');
                $table->index(['lead_id', 'occurred_at'], 'cpe_lead_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_performance_events');

        if (Schema::hasTable('estimate_outcomes') && Schema::hasColumn('estimate_outcomes', 'environmental_context')) {
            Schema::table('estimate_outcomes', function (Blueprint $table) {
                $table->dropColumn('environmental_context');
            });
        }

        Schema::dropIfExists('ai_conversation_logs');
    }
};
