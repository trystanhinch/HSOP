<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learning Centre easy extras:
 * - Widen pricing_override_logs.reason to TEXT (was VARCHAR).
 * Photo tables already have Laravel timestamps — no schema change needed there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_override_logs') && Schema::hasColumn('pricing_override_logs', 'reason')) {
            DB::statement('ALTER TABLE pricing_override_logs MODIFY reason TEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pricing_override_logs') && Schema::hasColumn('pricing_override_logs', 'reason')) {
            DB::statement('ALTER TABLE pricing_override_logs MODIFY reason VARCHAR(255) NULL');
        }
    }
};
