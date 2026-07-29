<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A-06 / A-22 — Brand identity snapshot.
 *
 * Adds brand_name_snapshot to quotes and invoices so that the operating brand
 * name displayed on a historical document is frozen at creation time.
 * Changing the Brand Content company_name later must not silently update past records.
 *
 * Backfills existing rows from the Brand via the lead_id / job_id FK chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'brand_name_snapshot')) {
                $table->string('brand_name_snapshot')->nullable()->after('status');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'brand_name_snapshot')) {
                $table->string('brand_name_snapshot')->nullable()->after('status');
            }
        });

        // Backfill quotes: resolve brand via lead_id or job → lead FK chain.
        DB::statement("
            UPDATE quotes q
            LEFT JOIN leads l ON l.id = q.lead_id
            LEFT JOIN jobs j ON j.id = q.job_id
            LEFT JOIN leads jl ON jl.id = j.lead_id
            LEFT JOIN brands b ON b.id = COALESCE(l.brand_id, jl.brand_id)
            SET q.brand_name_snapshot = b.company_name
            WHERE q.brand_name_snapshot IS NULL AND b.id IS NOT NULL
        ");

        // Backfill invoices: resolve brand via job → lead FK chain.
        DB::statement("
            UPDATE invoices i
            LEFT JOIN jobs j ON j.id = i.job_id
            LEFT JOIN leads l ON l.id = j.lead_id
            LEFT JOIN brands b ON b.id = l.brand_id
            SET i.brand_name_snapshot = b.company_name
            WHERE i.brand_name_snapshot IS NULL AND b.id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            if (Schema::hasColumn('quotes', 'brand_name_snapshot')) {
                $table->dropColumn('brand_name_snapshot');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'brand_name_snapshot')) {
                $table->dropColumn('brand_name_snapshot');
            }
        });
    }
};
