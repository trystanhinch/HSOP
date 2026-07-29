<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-16 / A-19 / A-21 — template versions, delivery log enrichment, channel health cache keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_template_versions')) {
            Schema::create('message_template_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_template_id')->constrained('message_templates')->cascadeOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->string('label')->nullable();
                $table->text('body');
                $table->string('channel', 16)->nullable();
                $table->json('variables')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('change_reason')->nullable();
                $table->timestamps();
                $table->unique(['message_template_id', 'version']);
            });
        }

        // Widen status enums so provider_unavailable / blocked_* are not coerced to "sent".
        if (Schema::hasTable('sms_logs')) {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE sms_logs MODIFY status VARCHAR(40) NOT NULL DEFAULT 'sent'"
            );
        }
        if (Schema::hasTable('email_logs')) {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE email_logs MODIFY status VARCHAR(40) NOT NULL DEFAULT 'sent'"
            );
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('sms_logs', 'recipient_normalized')) {
                $table->string('recipient_normalized', 32)->nullable()->after('to_phone');
            }
            if (! Schema::hasColumn('sms_logs', 'error_code')) {
                $table->string('error_code', 64)->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('sms_logs', 'error_plain')) {
                $table->string('error_plain', 500)->nullable()->after('error_code');
            }
            if (! Schema::hasColumn('sms_logs', 'attempt_count')) {
                $table->unsignedSmallInteger('attempt_count')->default(1)->after('error_plain');
            }
            if (! Schema::hasColumn('sms_logs', 'related_lead_id')) {
                $table->unsignedBigInteger('related_lead_id')->nullable()->after('related_job_id');
            }
            if (! Schema::hasColumn('sms_logs', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('related_lead_id');
            }
            if (! Schema::hasColumn('sms_logs', 'retry_of_id')) {
                $table->unsignedBigInteger('retry_of_id')->nullable()->after('brand_id');
            }
            if (! Schema::hasColumn('sms_logs', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('retry_of_id');
            }
            if (! Schema::hasColumn('sms_logs', 'correction_path')) {
                $table->string('correction_path', 255)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('sms_logs', 'is_critical')) {
                $table->boolean('is_critical')->default(false)->after('correction_path');
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('email_logs', 'recipient_normalized')) {
                $table->string('recipient_normalized')->nullable()->after('to_email');
            }
            if (! Schema::hasColumn('email_logs', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('status');
            }
            if (! Schema::hasColumn('email_logs', 'error_code')) {
                $table->string('error_code', 64)->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('email_logs', 'error_plain')) {
                $table->string('error_plain', 500)->nullable()->after('error_code');
            }
            if (! Schema::hasColumn('email_logs', 'attempt_count')) {
                $table->unsignedSmallInteger('attempt_count')->default(1)->after('error_plain');
            }
            if (! Schema::hasColumn('email_logs', 'related_lead_id')) {
                $table->unsignedBigInteger('related_lead_id')->nullable()->after('related_job_id');
            }
            if (! Schema::hasColumn('email_logs', 'brand_id')) {
                $table->unsignedBigInteger('brand_id')->nullable()->after('related_lead_id');
            }
            if (! Schema::hasColumn('email_logs', 'retry_of_id')) {
                $table->unsignedBigInteger('retry_of_id')->nullable()->after('brand_id');
            }
            if (! Schema::hasColumn('email_logs', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('retry_of_id');
            }
            if (! Schema::hasColumn('email_logs', 'correction_path')) {
                $table->string('correction_path', 255)->nullable()->after('idempotency_key');
            }
            if (! Schema::hasColumn('email_logs', 'is_critical')) {
                $table->boolean('is_critical')->default(false)->after('correction_path');
            }
            if (! Schema::hasColumn('email_logs', 'message_body')) {
                $table->text('message_body')->nullable()->after('trigger_event');
            }
            if (! Schema::hasColumn('email_logs', 'subject')) {
                $table->string('subject')->nullable()->after('message_body');
            }
        });

        // Soft unique for idempotent retries (MySQL allows multiple NULLs).
        if (Schema::hasColumn('sms_logs', 'idempotency_key')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                $table->index('idempotency_key');
                $table->index(['trigger_event', 'status']);
                $table->index('brand_id');
            });
        }
        if (Schema::hasColumn('email_logs', 'idempotency_key')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->index('idempotency_key');
                $table->index(['trigger_event', 'status']);
                $table->index('brand_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_versions');

        Schema::table('sms_logs', function (Blueprint $table) {
            foreach ([
                'recipient_normalized', 'error_code', 'error_plain', 'attempt_count',
                'related_lead_id', 'brand_id', 'retry_of_id', 'idempotency_key',
                'correction_path', 'is_critical',
            ] as $col) {
                if (Schema::hasColumn('sms_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('email_logs', function (Blueprint $table) {
            foreach ([
                'recipient_normalized', 'provider_message_id', 'error_code', 'error_plain',
                'attempt_count', 'related_lead_id', 'brand_id', 'retry_of_id',
                'idempotency_key', 'correction_path', 'is_critical', 'message_body', 'subject',
            ] as $col) {
                if (Schema::hasColumn('email_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
