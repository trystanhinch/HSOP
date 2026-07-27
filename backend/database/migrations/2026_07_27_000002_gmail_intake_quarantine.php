<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-02: Gmail lead intake quarantine + audit trail.
 * Messages land here before any Lead/Customer/notification is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_quarantine')) {
            Schema::create('intake_quarantine', function (Blueprint $table) {
                $table->id();
                $table->string('channel', 40)->default('gmail'); // gmail|manual|test
                $table->string('status', 40)->default('pending'); // pending|approved|ignored|auto_approved
                $table->string('mailbox_email')->nullable();
                $table->string('gmail_message_id')->nullable()->index();
                $table->string('gmail_thread_id')->nullable();
                $table->string('message_id_hash', 64)->nullable()->index(); // idempotency for non-gmail
                $table->text('raw_email');
                $table->string('subject')->nullable();
                $table->string('from_header')->nullable();
                $table->string('email_format', 40)->nullable(); // form|voicemail
                $table->json('parsed_fields')->nullable();
                $table->json('field_confidence')->nullable(); // [{field, score_0_100, source_text, valid}]
                $table->json('validation_errors')->nullable();
                $table->string('quarantine_reason')->nullable();
                $table->unsignedBigInteger('company_source_id')->nullable()->index();
                $table->string('duplicate_group_key')->nullable()->index();
                $table->unsignedBigInteger('duplicate_of_quarantine_id')->nullable();
                $table->unsignedBigInteger('converted_lead_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('ignore_reason')->nullable();
                $table->boolean('is_test_data')->default(false)->index();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('intake_audit_logs')) {
            Schema::create('intake_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('intake_quarantine_id')->nullable()->index();
                $table->unsignedBigInteger('lead_id')->nullable()->index();
                $table->string('actor_type', 40); // system|user
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('decision', 40); // auto_approved|quarantined|ignored|manually_approved|edited_approved
                $table->string('reason')->nullable();
                $table->text('source_text')->nullable();
                $table->json('confidence')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Allow-list helpers on company_sources (ADM-22): optional subject patterns JSON.
        if (Schema::hasTable('company_sources') && ! Schema::hasColumn('company_sources', 'intake_allow_patterns')) {
            Schema::table('company_sources', function (Blueprint $table) {
                $table->json('intake_allow_patterns')->nullable()->after('lead_parsing_rule');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_sources') && Schema::hasColumn('company_sources', 'intake_allow_patterns')) {
            Schema::table('company_sources', function (Blueprint $table) {
                $table->dropColumn('intake_allow_patterns');
            });
        }
        Schema::dropIfExists('intake_audit_logs');
        Schema::dropIfExists('intake_quarantine');
    }
};
