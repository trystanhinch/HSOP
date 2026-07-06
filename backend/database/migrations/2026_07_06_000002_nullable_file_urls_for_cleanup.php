<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_update_photos')) {
            DB::statement('ALTER TABLE job_update_photos MODIFY file_url VARCHAR(255) NULL');
        }

        if (Schema::hasTable('revision_request_photos')) {
            DB::statement('ALTER TABLE revision_request_photos MODIFY file_url VARCHAR(255) NULL');
        }

        if (Schema::hasTable('contractor_documents')) {
            DB::statement('ALTER TABLE contractor_documents MODIFY file_url VARCHAR(255) NULL');
        }

        if (Schema::hasTable('lead_photos')) {
            DB::statement('ALTER TABLE lead_photos MODIFY file_url VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        //
    }
};
