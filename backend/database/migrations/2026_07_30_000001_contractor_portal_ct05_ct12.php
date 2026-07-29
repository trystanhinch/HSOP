<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CT-05…CT-12 — contractor assignment lifecycle + availability preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('site_visits', 'assignment_state')) {
                $table->string('assignment_state', 32)->default('offered')->after('status');
            }
            if (! Schema::hasColumn('site_visits', 'respond_by')) {
                $table->timestamp('respond_by')->nullable()->after('assignment_state');
            }
            if (! Schema::hasColumn('site_visits', 'viewed_at')) {
                $table->timestamp('viewed_at')->nullable()->after('respond_by');
            }
            if (! Schema::hasColumn('site_visits', 'decline_reason')) {
                $table->string('decline_reason', 500)->nullable()->after('declined_at');
            }
            if (! Schema::hasColumn('site_visits', 'reassigned_at')) {
                $table->timestamp('reassigned_at')->nullable()->after('decline_reason');
            }
            if (! Schema::hasColumn('site_visits', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('site_visits', 'previous_contractor_id')) {
                $table->unsignedBigInteger('previous_contractor_id')->nullable()->after('contractor_id');
            }
        });

        Schema::table('contractors', function (Blueprint $table) {
            if (! Schema::hasColumn('contractors', 'working_hours')) {
                $table->json('working_hours')->nullable()->after('cities');
            }
            if (! Schema::hasColumn('contractors', 'blackout_ranges')) {
                $table->json('blackout_ranges')->nullable()->after('working_hours');
            }
            if (! Schema::hasColumn('contractors', 'min_notice_hours')) {
                $table->unsignedSmallInteger('min_notice_hours')->default(24)->after('blackout_ranges');
            }
            if (! Schema::hasColumn('contractors', 'daily_capacity')) {
                $table->unsignedTinyInteger('daily_capacity')->default(3)->after('min_notice_hours');
            }
            if (! Schema::hasColumn('contractors', 'availability_paused')) {
                $table->boolean('availability_paused')->default(false)->after('daily_capacity');
            }
            if (! Schema::hasColumn('contractors', 'availability_paused_until')) {
                $table->date('availability_paused_until')->nullable()->after('availability_paused');
            }
            if (! Schema::hasColumn('contractors', 'availability_notes')) {
                $table->text('availability_notes')->nullable()->after('availability_paused_until');
            }
        });

        // Backfill: accepted visits → accepted/confirmed; declined → declined; else offered
        if (Schema::hasColumn('site_visits', 'assignment_state')) {
            \DB::table('site_visits')->whereNotNull('accepted_at')->whereNull('confirmed_at')
                ->update([
                    'assignment_state' => 'accepted',
                    'confirmed_at' => \DB::raw('accepted_at'),
                ]);
            \DB::table('site_visits')->whereNotNull('declined_at')
                ->update(['assignment_state' => 'declined']);
            \DB::table('site_visits')
                ->whereNull('accepted_at')
                ->whereNull('declined_at')
                ->where(function ($q) {
                    $q->whereNull('assignment_state')->orWhere('assignment_state', '');
                })
                ->update(['assignment_state' => 'offered']);
        }
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            foreach (['assignment_state', 'respond_by', 'viewed_at', 'decline_reason', 'reassigned_at', 'confirmed_at', 'previous_contractor_id'] as $col) {
                if (Schema::hasColumn('site_visits', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('contractors', function (Blueprint $table) {
            foreach (['working_hours', 'blackout_ranges', 'min_notice_hours', 'daily_capacity', 'availability_paused', 'availability_paused_until', 'availability_notes'] as $col) {
                if (Schema::hasColumn('contractors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
