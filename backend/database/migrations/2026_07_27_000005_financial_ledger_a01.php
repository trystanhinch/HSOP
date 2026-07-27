<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-01 / A-26 / A-28 — financial ledger events + immutable payout history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            Schema::create('financial_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_type', 64); // invoice_issued, payment_received, refund, dispute, payout_*, gst_collected, stripe_fee
                $table->string('direction', 8)->default('credit'); // credit|debit
                $table->decimal('amount', 12, 2)->default(0); // ex-GST unless entry is gst_*
                $table->decimal('gst_amount', 12, 2)->nullable();
                $table->string('currency', 3)->default('CAD');
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->foreignId('payout_id')->nullable()->constrained('payouts')->nullOnDelete();
                $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('reference', 191)->nullable(); // transfer id, payment ref
                $table->json('meta')->nullable();
                $table->boolean('is_test_data')->default(false);
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['entry_type', 'occurred_at']);
                $table->index(['job_id', 'entry_type']);
                $table->index('is_test_data');
            });
        }

        if (! Schema::hasTable('payout_events')) {
            Schema::create('payout_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->string('event_type', 64); // created, amount_changed, held, released, approved, paid, failed, retried, reversed
                $table->string('from_status', 64)->nullable();
                $table->string('to_status', 64)->nullable();
                $table->decimal('amount', 12, 2)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('snapshot')->nullable(); // immutable payout row snapshot
                $table->text('notes')->nullable();
                $table->boolean('is_test_data')->default(false);
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['payout_id', 'occurred_at']);
                $table->index(['job_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_events');
        Schema::dropIfExists('financial_ledger_entries');
    }
};
