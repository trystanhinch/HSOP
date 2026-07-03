<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'contractor_price')) {
                $table->decimal('contractor_price', 10, 2)->nullable()->after('site_visit_notes');
            }
            if (! Schema::hasColumn('leads', 'contractor_price_submitted_at')) {
                $table->timestamp('contractor_price_submitted_at')->nullable()->after('contractor_price');
            }
            if (! Schema::hasColumn('leads', 'contractor_price_notes')) {
                $table->text('contractor_price_notes')->nullable()->after('contractor_price_submitted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['contractor_price', 'contractor_price_submitted_at', 'contractor_price_notes']);
        });
    }
};
