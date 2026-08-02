<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 5 — normalized property model (additive; existing free-text addresses untouched).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
                $table->id();
                $table->text('raw_address');
                $table->string('street', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('postal_code', 32)->nullable();
                $table->string('property_type', 32)->nullable(); // residential|commercial
                $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
                $table->timestamps();

                $table->index('postal_code');
                $table->index('city');
            });
        }

        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'property_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreignId('property_id')->nullable()->after('address')->constrained('properties')->nullOnDelete();
            });
        }

        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'property_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->foreignId('property_id')->nullable()->after('address')->constrained('properties')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'property_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('property_id');
            });
        }
        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'property_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropConstrainedForeignId('property_id');
            });
        }
        Schema::dropIfExists('properties');
    }
};
