<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-17 / A-18 / A-27 — AI mode enforcement, traceable/redacted logs, Command Center evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_action_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_action_logs', 'trace_id')) {
                $table->uuid('trace_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('ai_action_logs', 'parent_log_id')) {
                $table->unsignedBigInteger('parent_log_id')->nullable()->after('trace_id')->index();
            }
            if (! Schema::hasColumn('ai_action_logs', 'action_key')) {
                $table->string('action_key', 100)->nullable()->after('action_taken')->index();
            }
            if (! Schema::hasColumn('ai_action_logs', 'module')) {
                $table->string('module', 60)->nullable()->after('action_key');
            }
            if (! Schema::hasColumn('ai_action_logs', 'mode')) {
                $table->string('mode', 30)->nullable()->after('module');
            }
            if (! Schema::hasColumn('ai_action_logs', 'risk_level')) {
                $table->string('risk_level', 20)->nullable()->after('mode');
            }
            if (! Schema::hasColumn('ai_action_logs', 'ai_model')) {
                $table->string('ai_model', 80)->nullable()->after('risk_level');
            }
            if (! Schema::hasColumn('ai_action_logs', 'prompt_version')) {
                $table->string('prompt_version', 60)->nullable()->after('ai_model');
            }
            if (! Schema::hasColumn('ai_action_logs', 'tokens_prompt')) {
                $table->unsignedInteger('tokens_prompt')->nullable()->after('prompt_version');
            }
            if (! Schema::hasColumn('ai_action_logs', 'tokens_completion')) {
                $table->unsignedInteger('tokens_completion')->nullable()->after('tokens_prompt');
            }
            if (! Schema::hasColumn('ai_action_logs', 'cost_usd')) {
                $table->decimal('cost_usd', 10, 6)->nullable()->after('tokens_completion');
            }
            if (! Schema::hasColumn('ai_action_logs', 'latency_ms')) {
                $table->unsignedInteger('latency_ms')->nullable()->after('cost_usd');
            }
            if (! Schema::hasColumn('ai_action_logs', 'retry_count')) {
                $table->unsignedSmallInteger('retry_count')->default(0)->after('latency_ms');
            }
            if (! Schema::hasColumn('ai_action_logs', 'idempotency_key')) {
                $table->string('idempotency_key', 191)->nullable()->after('retry_count');
            }
            if (! Schema::hasColumn('ai_action_logs', 'linked_type')) {
                $table->string('linked_type', 80)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('ai_action_logs', 'linked_id')) {
                $table->unsignedBigInteger('linked_id')->nullable()->after('linked_type');
            }
            if (! Schema::hasColumn('ai_action_logs', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('linked_id')->index();
            }
            if (! Schema::hasColumn('ai_action_logs', 'outcome')) {
                $table->string('outcome', 40)->nullable()->after('brand_id')->index();
            }
            if (! Schema::hasColumn('ai_action_logs', 'is_simulation')) {
                $table->boolean('is_simulation')->default(false)->after('outcome');
            }
            if (! Schema::hasColumn('ai_action_logs', 'approval_log_id')) {
                $table->unsignedBigInteger('approval_log_id')->nullable()->after('is_simulation');
            }
        });

        try {
            Schema::table('ai_action_logs', function (Blueprint $table) {
                $table->unique('idempotency_key', 'ai_action_logs_idempotency_key_unique');
            });
        } catch (\Throwable) {
            // Index may already exist on re-run.
        }

        Schema::table('ai_action_types', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_action_types', 'risk_level')) {
                $table->string('risk_level', 20)->default('medium')->after('requires_human_approval');
            }
            if (! Schema::hasColumn('ai_action_types', 'module')) {
                $table->string('module', 60)->nullable()->after('risk_level');
            }
            if (! Schema::hasColumn('ai_action_types', 'hard_approval_floor')) {
                $table->boolean('hard_approval_floor')->default(false)->after('module');
            }
        });

        Schema::table('ai_conversation_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_conversation_logs', 'content_preview')) {
                $table->string('content_preview', 160)->nullable()->after('content');
            }
            if (! Schema::hasColumn('ai_conversation_logs', 'trace_id')) {
                $table->uuid('trace_id')->nullable()->after('intake_session_id')->index();
            }
        });

        if (! Schema::hasTable('ai_command_saved_queries')) {
            Schema::create('ai_command_saved_queries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name');
                $table->text('query_text');
                $table->timestamps();
                $table->index(['user_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_command_saved_queries');

        Schema::table('ai_conversation_logs', function (Blueprint $table) {
            foreach (['content_preview', 'trace_id'] as $col) {
                if (Schema::hasColumn('ai_conversation_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('ai_action_types', function (Blueprint $table) {
            foreach (['risk_level', 'module', 'hard_approval_floor'] as $col) {
                if (Schema::hasColumn('ai_action_types', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('ai_action_logs', function (Blueprint $table) {
            $cols = [
                'trace_id', 'parent_log_id', 'action_key', 'module', 'mode', 'risk_level',
                'ai_model', 'prompt_version', 'tokens_prompt', 'tokens_completion', 'cost_usd',
                'latency_ms', 'retry_count', 'idempotency_key', 'linked_type', 'linked_id',
                'brand_id', 'outcome', 'is_simulation', 'approval_log_id',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('ai_action_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
