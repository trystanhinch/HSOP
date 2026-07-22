<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payouts') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payouts MODIFY COLUMN payout_type ENUM('contractor','pm','company') NOT NULL DEFAULT 'contractor'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payouts') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payouts MODIFY COLUMN payout_type ENUM('contractor','pm') NOT NULL DEFAULT 'contractor'");
        }
    }
};
