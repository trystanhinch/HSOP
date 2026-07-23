<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant brands use arbitrary service keys — enum cannot stay drywall/insulation-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'service_category')) {
            return;
        }

        // MySQL enum → varchar; keep existing values intact.
        DB::statement('ALTER TABLE leads MODIFY service_category VARCHAR(80) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'service_category')) {
            return;
        }

        DB::statement("ALTER TABLE leads MODIFY service_category ENUM('drywall_paint', 'insulation') NULL");
    }
};
