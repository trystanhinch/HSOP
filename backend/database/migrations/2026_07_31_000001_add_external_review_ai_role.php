<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A Phase 4 — dedicated External Review AI identity.
 * Adds external_review_ai to users.role. Does NOT remove ai_super_admin
 * (operational Command Center / AiActionGate actor remains separate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin', 'content_editor', 'external_review_ai') NOT NULL DEFAULT 'customer'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Reassign any external_review_ai rows before shrinking the ENUM.
        DB::table('users')
            ->where('role', 'external_review_ai')
            ->update(['role' => 'ai_super_admin', 'status' => 'inactive']);

        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'pm', 'contractor', 'customer', 'ai_super_admin', 'content_editor') NOT NULL DEFAULT 'customer'");
    }
};
