<?php

use App\Services\Ledger\LedgerAppendOnlyTriggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Milestone 6A Phase 6 — DB-level append-only enforcement (SEC-09 tamper layer).
 *
 * Creates BEFORE UPDATE and BEFORE DELETE triggers on:
 *   - review_gateway_access_logs
 *   - learning_gateway_access_logs
 *   - ai_evaluation_runs
 *   - ai_evaluation_findings
 *
 * =============================================================================
 * ESCAPE HATCH — future schema changes on these tables (READ THIS)
 * =============================================================================
 * These triggers block EVERY row UPDATE and DELETE. They do NOT block INSERT.
 * They do NOT fire on DROP TABLE / CREATE TABLE (so migrate:fresh is fine).
 * MySQL TRUNCATE TABLE also does NOT fire DELETE triggers (verified in tests) —
 * treat truncate as a privileged DDL escape, not as protected by this layer.
 *
 * Any future migration that must ALTER these tables' structure, or any
 * deliberate cleanup that needs DELETE/UPDATE rows, MUST be an explicit,
 * multi-step operation — do NOT rely on a silent bypass:
 *
 *   1. DROP the triggers first, e.g.:
 *        php artisan ledger:reapply-triggers --drop-only --force
 *      or in SQL:
 *        DROP TRIGGER IF EXISTS review_gateway_access_logs_bu_append_only;
 *        DROP TRIGGER IF EXISTS review_gateway_access_logs_bd_append_only;
 *        (repeat for each of the four tables)
 *   2. Run your ALTER / data repair.
 *   3. Re-apply triggers:
 *        php artisan ledger:reapply-triggers --force
 *      or re-run this migration's up() via:
 *        php artisan migrate --path=database/migrations/2026_07_31_200001_add_append_only_ledger_triggers.php --force
 *        (only works if the migration is rolled back first, or use the artisan command)
 *
 * Verify health anytime (read-only):
 *   php artisan ledger:verify-triggers
 *
 * Eloquent booted() LogicException guards remain in place as a first line of
 * defense; this migration is the independent second layer underneath.
 * =============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            // Triggers use MySQL SIGNAL; Feature suite forces mysql for these ledgers.
            return;
        }

        LedgerAppendOnlyTriggers::applyAll();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        LedgerAppendOnlyTriggers::dropAll();
    }
};
