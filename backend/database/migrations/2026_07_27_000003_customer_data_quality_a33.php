<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit A-33: customer data quality, duplicate grouping, consent, merge audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (! Schema::hasColumn('customers', 'data_quality_flags')) {
                    $table->json('data_quality_flags')->nullable()->after('portal_link_status');
                }
                if (! Schema::hasColumn('customers', 'duplicate_group_id')) {
                    $table->string('duplicate_group_id', 64)->nullable()->index()->after('data_quality_flags');
                }
                if (! Schema::hasColumn('customers', 'is_duplicate_primary')) {
                    $table->boolean('is_duplicate_primary')->default(true)->after('duplicate_group_id');
                }
                if (! Schema::hasColumn('customers', 'merged_into_customer_id')) {
                    $table->unsignedBigInteger('merged_into_customer_id')->nullable()->index()->after('is_duplicate_primary');
                }
                if (! Schema::hasColumn('customers', 'merged_at')) {
                    $table->timestamp('merged_at')->nullable()->after('merged_into_customer_id');
                }
                if (! Schema::hasColumn('customers', 'communication_preference')) {
                    $table->string('communication_preference', 20)->default('both')->after('merged_at');
                }
                if (! Schema::hasColumn('customers', 'do_not_contact')) {
                    $table->boolean('do_not_contact')->default(false)->index()->after('communication_preference');
                }
                if (! Schema::hasColumn('customers', 'consent_source')) {
                    $table->string('consent_source')->nullable()->after('do_not_contact');
                }
                if (! Schema::hasColumn('customers', 'consent_recorded_at')) {
                    $table->timestamp('consent_recorded_at')->nullable()->after('consent_source');
                }
                if (! Schema::hasColumn('customers', 'phone_normalized')) {
                    $table->string('phone_normalized', 20)->nullable()->index()->after('phone');
                }
            });
        }

        if (! Schema::hasTable('customer_merge_logs')) {
            Schema::create('customer_merge_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('primary_customer_id')->index();
                $table->json('merged_customer_ids');
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->json('pre_merge_snapshot');
                $table->json('field_choices')->nullable();
                $table->json('reassignment_counts')->nullable();
                $table->string('status', 40)->default('completed'); // completed|failed
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merge_logs');

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                foreach ([
                    'data_quality_flags',
                    'duplicate_group_id',
                    'is_duplicate_primary',
                    'merged_into_customer_id',
                    'merged_at',
                    'communication_preference',
                    'do_not_contact',
                    'consent_source',
                    'consent_recorded_at',
                    'phone_normalized',
                ] as $col) {
                    if (Schema::hasColumn('customers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
