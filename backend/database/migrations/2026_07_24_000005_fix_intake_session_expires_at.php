<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * intake_sessions.expires_at was created as TIMESTAMP with ON UPDATE CURRENT_TIMESTAMP,
 * which overwrote the explicit TTL on every conversation save.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_sessions')) {
            return;
        }

        // Use DATETIME (no DEFAULT/ON UPDATE) so Laravel-owned TTL is not overwritten on save.
        DB::statement('ALTER TABLE intake_sessions CHANGE expires_at expires_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('intake_sessions')) {
            return;
        }

        DB::statement('ALTER TABLE intake_sessions CHANGE expires_at expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
