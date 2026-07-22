<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('invoices', 'amount_paid')) {
                    $table->decimal('amount_paid', 10, 2)->default(0)->after('balance');
                }
                if (! Schema::hasColumn('invoices', 'payment_date')) {
                    $table->date('payment_date')->nullable()->after('amount_paid');
                }
                if (! Schema::hasColumn('invoices', 'payment_method')) {
                    $table->string('payment_method', 50)->nullable()->after('payment_date');
                }
                if (! Schema::hasColumn('invoices', 'stripe_transaction_id')) {
                    $table->string('stripe_transaction_id')->nullable()->after('payment_method');
                }
                if (! Schema::hasColumn('invoices', 'source_company')) {
                    $table->string('source_company')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('invoices', 'company_source_id')) {
                    $table->foreignId('company_source_id')->nullable()->after('source_company')
                        ->constrained('company_sources')->nullOnDelete();
                }
            });

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
                    'draft','sent','invoice_sent','awaiting_payment','payment_pending','payment_failed',
                    'partially_paid','paid','refunded','disputed','overdue','cancelled','unpaid','partial'
                ) NOT NULL DEFAULT 'draft'");
            }
        }

        if (Schema::hasTable('payouts')) {
            Schema::table('payouts', function (Blueprint $table) {
                if (! Schema::hasColumn('payouts', 'pm_id')) {
                    $table->foreignId('pm_id')->nullable()->after('contractor_id')
                        ->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('payouts', 'split_type')) {
                    $table->string('split_type', 20)->nullable()->after('payout_type');
                }
                if (! Schema::hasColumn('payouts', 'eligible_at')) {
                    $table->timestamp('eligible_at')->nullable()->after('eligibility_status');
                }
                if (! Schema::hasColumn('payouts', 'scheduled_for')) {
                    $table->date('scheduled_for')->nullable()->after('eligible_at');
                }
                if (! Schema::hasColumn('payouts', 'stripe_transfer_id')) {
                    $table->string('stripe_transfer_id')->nullable()->after('scheduled_for');
                }
            });

            // Backfill split_type from payout_type
            DB::table('payouts')->whereNull('split_type')->whereNotNull('payout_type')
                ->update(['split_type' => DB::raw('payout_type')]);

            if (DB::getDriverName() === 'mysql') {
                DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM(
                    'not_eligible','waiting_for_payment','waiting_for_completion_acceptance',
                    'waiting_for_revision_closure','eligible','scheduled','pending','in_transit',
                    'paid','failed','on_hold',
                    'not_ready','ready_for_payout','approved','hold_issue'
                ) NOT NULL DEFAULT 'not_ready'");
            }
        }

        // Config-driven invoice numbering defaults
        $defaults = [
            'invoice_number_format' => 'INV-{XXXX}',
            'invoice_number_next' => '1',
            'payout_schedule_business_days' => '2',
        ];
        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                foreach (['company_source_id', 'source_company', 'stripe_transaction_id', 'payment_method', 'payment_date', 'amount_paid'] as $col) {
                    if (Schema::hasColumn('invoices', $col)) {
                        if ($col === 'company_source_id') {
                            $table->dropConstrainedForeignId('company_source_id');
                        } else {
                            $table->dropColumn($col);
                        }
                    }
                }
            });
        }

        if (Schema::hasTable('payouts')) {
            Schema::table('payouts', function (Blueprint $table) {
                if (Schema::hasColumn('payouts', 'pm_id')) {
                    $table->dropConstrainedForeignId('pm_id');
                }
                foreach (['split_type', 'eligible_at', 'scheduled_for', 'stripe_transfer_id'] as $col) {
                    if (Schema::hasColumn('payouts', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
