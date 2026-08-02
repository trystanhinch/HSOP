<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A Phase 10 — correlation_id on delivery/AI logs + Gmail staleness alert flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_logs') && ! Schema::hasColumn('sms_logs', 'correlation_id')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            });
        }

        if (Schema::hasTable('email_logs') && ! Schema::hasColumn('email_logs', 'correlation_id')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            });
        }

        if (Schema::hasTable('ai_action_logs') && ! Schema::hasColumn('ai_action_logs', 'correlation_id')) {
            Schema::table('ai_action_logs', function (Blueprint $table) {
                $table->string('correlation_id', 64)->nullable()->after('trace_id');
            });
        }

        if (Schema::hasTable('gmail_oauth_tokens') && ! Schema::hasColumn('gmail_oauth_tokens', 'staleness_alerted')) {
            Schema::table('gmail_oauth_tokens', function (Blueprint $table) {
                $table->boolean('staleness_alerted')->default(false)->after('last_fetched_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_logs') && Schema::hasColumn('sms_logs', 'correlation_id')) {
            Schema::table('sms_logs', fn (Blueprint $t) => $t->dropColumn('correlation_id'));
        }
        if (Schema::hasTable('email_logs') && Schema::hasColumn('email_logs', 'correlation_id')) {
            Schema::table('email_logs', fn (Blueprint $t) => $t->dropColumn('correlation_id'));
        }
        if (Schema::hasTable('ai_action_logs') && Schema::hasColumn('ai_action_logs', 'correlation_id')) {
            Schema::table('ai_action_logs', fn (Blueprint $t) => $t->dropColumn('correlation_id'));
        }
        if (Schema::hasTable('gmail_oauth_tokens') && Schema::hasColumn('gmail_oauth_tokens', 'staleness_alerted')) {
            Schema::table('gmail_oauth_tokens', fn (Blueprint $t) => $t->dropColumn('staleness_alerted'));
        }
    }
};
