<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'sms_enabled')) {
                $table->boolean('sms_enabled')->default(true)->after('status');
            }
        });

        Schema::table('jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('jobs', 'ready_for_review_at')) {
                $table->timestamp('ready_for_review_at')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'corrections_notes')) {
                $table->text('corrections_notes')->nullable();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'payout_method')) {
                $table->string('payout_method')->nullable();
            }
            if (! Schema::hasColumn('payouts', 'payout_due_date')) {
                $table->date('payout_due_date')->nullable();
            }
            if (! Schema::hasColumn('payouts', 'admin_notes')) {
                $table->text('admin_notes')->nullable();
            }
        });

        if (! Schema::hasTable('sms_logs')) {
            Schema::create('sms_logs', function (Blueprint $table) {
                $table->id();
                $table->string('to_phone');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('trigger_event');
                $table->foreignId('related_job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->text('message_body');
                $table->enum('status', ['sent', 'failed', 'disabled'])->default('sent');
                $table->string('provider_message_id')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('email_logs')) {
            Schema::create('email_logs', function (Blueprint $table) {
                $table->id();
                $table->string('to_email');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('trigger_event');
                $table->foreignId('related_job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->enum('status', ['sent', 'failed'])->default('sent');
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('settings');

        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['payout_method', 'payout_due_date', 'admin_notes']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['ready_for_review_at', 'completed_at', 'corrections_notes']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'sms_enabled']);
        });
    }
};
