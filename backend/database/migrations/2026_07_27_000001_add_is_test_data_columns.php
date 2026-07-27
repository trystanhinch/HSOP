<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-05: separate test/placeholder records from production business data.
 */
return new class extends Migration
{
    /**
     * Tables that store per-record business / ops data and must support is_test_data.
     *
     * @var list<string>
     */
    private array $tables = [
        'users',
        'companies',
        'company_sources',
        'brands',
        'contractors',
        'customers',
        'leads',
        'jobs',
        'quotes',
        'invoices',
        'payments',
        'payouts',
        'pricing_rules',
        'sms_logs',
        'email_logs',
        'activity_timeline_entries',
        'next_actions',
        'site_visits',
        'bookings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'is_test_data')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('is_test_data')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'is_test_data')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('is_test_data');
            });
        }
    }
};
