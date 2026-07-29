<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit CT-02 — lead-stage contractor↔PM message threads that carry forward on convert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'lead_id')) {
                $table->foreignId('lead_id')
                    ->nullable()
                    ->after('job_id')
                    ->constrained('leads')
                    ->nullOnDelete();
                $table->index(['lead_id', 'channel']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages') || ! Schema::hasColumn('messages', 'lead_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
