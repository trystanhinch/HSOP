<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-15 business hours thresholds, A-24 availability safeguards, A-30/A-31 scaffolding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_hours_profiles')) {
            Schema::create('business_hours_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id')->nullable()->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('name', 120)->default('Default');
                $table->string('timezone', 64)->default('America/Vancouver');
                // { "1": [["09:00","17:00"]], ... } Mon=1 .. Sun=7; empty = closed
                $table->json('weekly_hours')->nullable();
                // ["2026-12-25", "2026-01-01"]
                $table->json('holidays')->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_threshold_versions')) {
            Schema::create('workflow_threshold_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('thresholds');
                $table->json('preview_timeline')->nullable();
                $table->string('clock_mode', 20)->default('business'); // wall|business
                $table->unsignedBigInteger('business_hours_profile_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('availability_windows')) {
            Schema::table('availability_windows', function (Blueprint $table) {
                if (! Schema::hasColumn('availability_windows', 'blackout_dates')) {
                    $table->json('blackout_dates')->nullable()->after('timezone');
                }
                if (! Schema::hasColumn('availability_windows', 'travel_buffer_minutes')) {
                    $table->unsignedInteger('travel_buffer_minutes')->default(0)->after('blackout_dates');
                }
                if (! Schema::hasColumn('availability_windows', 'capacity')) {
                    $table->unsignedInteger('capacity')->default(1)->after('travel_buffer_minutes');
                }
                if (! Schema::hasColumn('availability_windows', 'effective_from')) {
                    $table->date('effective_from')->nullable()->after('capacity');
                }
                if (! Schema::hasColumn('availability_windows', 'effective_to')) {
                    $table->date('effective_to')->nullable()->after('effective_from');
                }
                if (! Schema::hasColumn('availability_windows', 'temporary_override')) {
                    $table->boolean('temporary_override')->default(false)->after('effective_to');
                }
                if (! Schema::hasColumn('availability_windows', 'notes')) {
                    $table->string('notes', 500)->nullable()->after('temporary_override');
                }
            });
        }

        if (Schema::hasTable('settings') && ! Schema::hasTable('workflow_threshold_settings_meta')) {
            // Store clock_mode / profile id in settings table via keys — no extra table needed.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_threshold_versions');
        Schema::dropIfExists('business_hours_profiles');
        if (Schema::hasTable('availability_windows')) {
            Schema::table('availability_windows', function (Blueprint $table) {
                foreach ([
                    'blackout_dates', 'travel_buffer_minutes', 'capacity',
                    'effective_from', 'effective_to', 'temporary_override', 'notes',
                ] as $col) {
                    if (Schema::hasColumn('availability_windows', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
