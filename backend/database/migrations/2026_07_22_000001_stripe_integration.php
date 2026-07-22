<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'stripe_account_id')) {
                    $table->string('stripe_account_id')->nullable()->after('sms_enabled');
                }
                if (! Schema::hasColumn('users', 'stripe_onboarding_status')) {
                    $table->string('stripe_onboarding_status', 40)->nullable()->after('stripe_account_id');
                }
                if (! Schema::hasColumn('users', 'stripe_requirements_due')) {
                    $table->json('stripe_requirements_due')->nullable()->after('stripe_onboarding_status');
                }
                if (! Schema::hasColumn('users', 'stripe_payout_ready')) {
                    $table->boolean('stripe_payout_ready')->default(false)->after('stripe_requirements_due');
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'stripe_checkout_session_id')) {
                    $table->string('stripe_checkout_session_id')->nullable()->after('stripe_transaction_id');
                }
                if (! Schema::hasColumn('invoices', 'stripe_payment_intent_id')) {
                    $table->string('stripe_payment_intent_id')->nullable()->after('stripe_checkout_session_id');
                }
            });
        }

        if (! Schema::hasTable('stripe_webhook_events')) {
            Schema::create('stripe_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id')->unique();
                $table->string('type', 100);
                $table->string('status', 40)->default('processed'); // processed|ignored|failed
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->json('payload_meta')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('payouts') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM(
                'not_eligible','waiting_for_payment','waiting_for_completion_acceptance',
                'waiting_for_revision_closure','eligible','scheduled','queued','pending','in_transit',
                'paid','failed','on_hold',
                'not_ready','ready_for_payout','approved','hold_issue'
            ) NOT NULL DEFAULT 'not_ready'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                foreach (['stripe_checkout_session_id', 'stripe_payment_intent_id'] as $col) {
                    if (Schema::hasColumn('invoices', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                foreach (['stripe_account_id', 'stripe_onboarding_status', 'stripe_requirements_due', 'stripe_payout_ready'] as $col) {
                    if (Schema::hasColumn('users', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
