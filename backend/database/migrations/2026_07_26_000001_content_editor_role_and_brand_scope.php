<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 — agency-scoped content role boundary.
 *
 * Adds content_editor to the users.role enum and a brand_id FK so a
 * content editor is tied to exactly one brand. No full CMS — access only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin', 'content_editor') NOT NULL DEFAULT 'customer'");
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'brand_id')) {
                $table->foreignId('brand_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('brands')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'brand_id')) {
                $table->dropConstrainedForeignId('brand_id');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin') NOT NULL DEFAULT 'customer'");
        }
    }
};
