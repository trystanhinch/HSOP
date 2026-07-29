<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit PM-04 — persist delivery + recipient metadata on messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'delivery_status')) {
                $table->string('delivery_status', 40)->nullable()->after('is_read');
            }
            if (! Schema::hasColumn('messages', 'recipient_label')) {
                $table->string('recipient_label', 255)->nullable()->after('delivery_status');
            }
            // lead_id is owned by CT-02 migration 2026_07_29_000001 (FK) — do not add/drop here.
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            foreach (['delivery_status', 'recipient_label'] as $col) {
                if (Schema::hasColumn('messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
