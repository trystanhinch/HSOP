<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A-08 — Normalize activity/payment pseudo-statuses off jobs.status.
 * progress_updated / update_posted → in_progress
 * paid / paid_completed → completed (payment remains on invoices)
 */
return new class extends Migration
{
    public function up(): void
    {
        $activity = DB::table('jobs')
            ->whereIn('status', ['progress_updated', 'update_posted'])
            ->update(['status' => 'in_progress', 'updated_at' => now()]);

        $paid = DB::table('jobs')
            ->whereIn('status', ['paid', 'paid_completed'])
            ->update(['status' => 'completed', 'updated_at' => now()]);

        Log::info('A-08 job status backfill', [
            'activity_to_in_progress' => $activity,
            'payment_to_completed' => $paid,
        ]);
    }

    public function down(): void
    {
        // Irreversible intentional remapping — no-op.
    }
};
