<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE jobs MODIFY COLUMN status ENUM(
            'new_job','contractor_assigned','quote_sent','quote_approved','scheduled',
            'in_progress','waiting_on_customer','ready_for_review','corrections_required',
            'completed_by_contractor','final_review','completed','invoiced','paid','cancelled'
        ) NOT NULL DEFAULT 'new_job'");

        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
            'draft','sent','invoice_sent','awaiting_payment','partially_paid','paid','overdue','cancelled',
            'unpaid','partial'
        ) NOT NULL DEFAULT 'draft'");

        DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM(
            'not_ready','ready_for_payout','pending','approved','paid','hold_issue'
        ) NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE jobs MODIFY COLUMN status ENUM(
            'new_job','contractor_assigned','quote_sent','quote_approved','scheduled',
            'in_progress','waiting_on_customer','completed_by_contractor','final_review','completed','cancelled'
        ) NOT NULL DEFAULT 'new_job'");

        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM(
            'draft','sent','awaiting_payment','partially_paid','paid','overdue','cancelled','unpaid','partial'
        ) NOT NULL DEFAULT 'draft'");

        DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM(
            'pending','approved','paid'
        ) NOT NULL DEFAULT 'pending'");
    }
};
