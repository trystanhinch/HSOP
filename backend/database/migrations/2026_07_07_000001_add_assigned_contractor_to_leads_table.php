<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'assigned_contractor_id')) {
                $table->foreignId('assigned_contractor_id')
                    ->nullable()
                    ->after('assigned_pm_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'assigned_contractor_id')) {
                $table->dropForeign(['assigned_contractor_id']);
                $table->dropColumn('assigned_contractor_id');
            }
        });
    }
};
