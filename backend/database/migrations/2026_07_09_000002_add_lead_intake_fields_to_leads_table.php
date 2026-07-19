<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'company_source_id')) {
                $table->foreignId('company_source_id')->nullable()->after('company_id')
                    ->constrained('company_sources')->nullOnDelete();
            }
            if (! Schema::hasColumn('leads', 'raw_email_copy')) {
                $table->longText('raw_email_copy')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('leads', 'parse_metadata')) {
                $table->json('parse_metadata')->nullable()->after('raw_email_copy');
            }
            if (! Schema::hasColumn('leads', 'needs_manual_review')) {
                $table->boolean('needs_manual_review')->default(false)->after('parse_metadata');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'company_source_id')) {
                $table->dropConstrainedForeignId('company_source_id');
            }
            foreach (['raw_email_copy', 'parse_metadata', 'needs_manual_review'] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
