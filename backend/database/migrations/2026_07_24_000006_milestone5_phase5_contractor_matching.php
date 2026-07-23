<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 Phase 5 — thin contractor matching for confirmed bookings.
 * CompanySource gains a contractor pool (mirrors default_pm_id pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_sources') && ! Schema::hasColumn('company_sources', 'default_contractor_ids')) {
            Schema::table('company_sources', function (Blueprint $table) {
                // List of user_ids (role=contractor) eligible for this brand/source
                $table->json('default_contractor_ids')->nullable()->after('default_pm_id');
            });
        }

        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (! Schema::hasColumn('bookings', 'auto_matched')) {
                    $table->boolean('auto_matched')->default(false)->after('contractor_id');
                }
                if (! Schema::hasColumn('bookings', 'match_rule')) {
                    $table->string('match_rule', 60)->nullable()->after('auto_matched');
                }
                if (! Schema::hasColumn('bookings', 'match_meta')) {
                    $table->json('match_meta')->nullable()->after('match_rule');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings')) {
            Schema::table('bookings', function (Blueprint $table) {
                if (Schema::hasColumn('bookings', 'match_meta')) {
                    $table->dropColumn('match_meta');
                }
                if (Schema::hasColumn('bookings', 'match_rule')) {
                    $table->dropColumn('match_rule');
                }
                if (Schema::hasColumn('bookings', 'auto_matched')) {
                    $table->dropColumn('auto_matched');
                }
            });
        }

        if (Schema::hasTable('company_sources') && Schema::hasColumn('company_sources', 'default_contractor_ids')) {
            Schema::table('company_sources', function (Blueprint $table) {
                $table->dropColumn('default_contractor_ids');
            });
        }
    }
};
