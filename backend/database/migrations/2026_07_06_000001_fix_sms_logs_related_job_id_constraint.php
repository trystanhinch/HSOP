<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                $table->dropForeign(['related_job_id']);
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->dropForeign(['related_job_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sms_logs')) {
            Schema::table('sms_logs', function (Blueprint $table) {
                $table->foreign('related_job_id')->references('id')->on('jobs')->nullOnDelete();
            });
        }

        if (Schema::hasTable('email_logs')) {
            Schema::table('email_logs', function (Blueprint $table) {
                $table->foreign('related_job_id')->references('id')->on('jobs')->nullOnDelete();
            });
        }
    }
};
