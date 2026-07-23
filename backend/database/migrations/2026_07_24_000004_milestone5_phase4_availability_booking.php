<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 Phase 4 — availability windows, soft holds, confirmed bookings.
 * slot_claims enforces DB-level uniqueness for conflict prevention.
 * Note: intake_sessions.id is UUID — do not use foreignId() for that FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('availability_windows')) {
            Schema::create('availability_windows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('service_category', 80)->nullable()->index();
                $table->unsignedTinyInteger('day_of_week')->nullable()->index();
                $table->date('specific_date')->nullable()->index();
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedSmallInteger('slot_duration_minutes')->default(60);
                $table->string('timezone', 64)->default('America/Vancouver');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->index(['brand_id', 'status']);
            });
        }

        if (! Schema::hasTable('slot_claims')) {
            Schema::create('slot_claims', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('resource_key', 64);
                $table->dateTime('slot_start');
                $table->dateTime('slot_end');
                $table->string('claim_type', 20);
                $table->unsignedBigInteger('claim_id');
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['brand_id', 'resource_key', 'slot_start'], 'slot_claims_unique_slot');
                $table->index(['brand_id', 'slot_start', 'slot_end']);
            });
        }

        if (! Schema::hasTable('booking_holds')) {
            Schema::create('booking_holds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->uuid('intake_session_id')->nullable()->index();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->string('hold_token', 64)->unique();
                $table->string('resource_key', 64);
                $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('service_category', 80)->nullable();
                $table->dateTime('slot_start');
                $table->dateTime('slot_end');
                $table->string('status', 20)->default('held')->index();
                $table->timestamp('held_until')->index();
                $table->timestamps();
                $table->index(['brand_id', 'status', 'slot_start']);
            });

            try {
                Schema::table('booking_holds', function (Blueprint $table) {
                    $table->foreign('intake_session_id')->references('id')->on('intake_sessions')->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }

        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->uuid('intake_session_id')->nullable()->index();
                $table->foreignId('booking_hold_id')->nullable()->constrained('booking_holds')->nullOnDelete();
                $table->unsignedBigInteger('site_visit_id')->nullable()->index();
                $table->string('resource_key', 64);
                $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('service_category', 80)->nullable();
                $table->dateTime('slot_start');
                $table->dateTime('slot_end');
                $table->string('timezone', 64)->default('America/Vancouver');
                $table->string('status', 20)->default('confirmed')->index();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->index(['brand_id', 'status', 'slot_start']);
                $table->unique(['brand_id', 'resource_key', 'slot_start'], 'bookings_unique_slot');
            });

            try {
                Schema::table('bookings', function (Blueprint $table) {
                    $table->foreign('intake_session_id')->references('id')->on('intake_sessions')->nullOnDelete();
                });
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_holds');
        Schema::dropIfExists('slot_claims');
        Schema::dropIfExists('availability_windows');
    }
};
