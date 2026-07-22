<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payments') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM(
                'e_transfer','stripe','card','other'
            ) NOT NULL DEFAULT 'e_transfer'");
        }
    }

    public function down(): void
    {
        // no-op — widening enum is safe to leave
    }
};
