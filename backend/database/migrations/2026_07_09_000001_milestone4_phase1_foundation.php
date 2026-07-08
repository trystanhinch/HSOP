<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin') NOT NULL DEFAULT 'customer'");
        }

        if (! Schema::hasTable('ai_action_logs')) {
            Schema::create('ai_action_logs', function (Blueprint $table) {
                $table->id();
                $table->string('trigger_event');
                $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
                $table->json('data_viewed')->nullable();
                $table->text('decision')->nullable();
                $table->string('action_taken')->nullable();
                $table->text('message_sent')->nullable();
                $table->string('recipient')->nullable();
                $table->string('status_before')->nullable();
                $table->string('status_after')->nullable();
                $table->string('rule_applied')->nullable();
                $table->boolean('required_human_approval')->default(false);
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['trigger_event', 'created_at']);
                $table->index('action_taken');
            });
        }

        if (! Schema::hasTable('ai_action_types')) {
            Schema::create('ai_action_types', function (Blueprint $table) {
                $table->id();
                $table->string('action_key')->unique();
                $table->string('label');
                $table->string('permission_level')->default('ai_super_admin');
                $table->boolean('requires_human_approval')->default(true);
                $table->json('modes_available');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('company_sources')) {
            Schema::create('company_sources', function (Blueprint $table) {
                $table->id();
                $table->string('company_name');
                $table->string('domain')->nullable();
                $table->json('service_categories')->nullable();
                $table->string('google_review_url')->nullable();
                $table->foreignId('default_pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sender_identity')->nullable();
                $table->text('lead_parsing_rule')->nullable();
                $table->decimal('marketing_cost_monthly', 10, 2)->nullable();
                $table->enum('status', ['active', 'paused', 'testing', 'archived'])->default('active');
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasTable('next_actions')) {
            Schema::create('next_actions', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->string('action_description');
                $table->enum('responsible_role', ['owner', 'ai', 'pm', 'contractor', 'customer']);
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('due_at')->nullable();
                $table->enum('status', ['pending', 'completed', 'overdue', 'escalated'])->default('pending');
                $table->dateTime('last_action_at')->nullable();
                $table->string('escalation_rule')->nullable();
                $table->timestamps();

                $table->index(['subject_type', 'subject_id', 'status'], 'next_actions_subject_status_idx');
            });
        }

        if (! Schema::hasTable('activity_timeline_entries')) {
            Schema::create('activity_timeline_entries', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type');
                $table->unsignedBigInteger('subject_id');
                $table->string('event_type');
                $table->string('actor_type');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->text('description');
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['subject_type', 'subject_id', 'occurred_at'], 'timeline_subject_occurred_idx');
                $table->index(['actor_type', 'actor_id'], 'timeline_actor_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_timeline_entries');
        Schema::dropIfExists('next_actions');
        Schema::dropIfExists('company_sources');
        Schema::dropIfExists('ai_action_types');
        Schema::dropIfExists('ai_action_logs');

        if (Schema::hasTable('users') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer') NOT NULL DEFAULT 'customer'");
        }
    }
};
