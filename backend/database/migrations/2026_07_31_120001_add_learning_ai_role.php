<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6B Phase 1 — dedicated Learning AI service identity.
 * Additive only: does not remove or alter existing roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin', 'content_editor', 'external_review_ai', 'learning_ai') NOT NULL DEFAULT 'customer'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('users')
            ->where('role', 'learning_ai')
            ->update(['role' => 'ai_super_admin', 'status' => 'inactive']);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin', 'content_editor', 'external_review_ai') NOT NULL DEFAULT 'customer'");
    }
};
