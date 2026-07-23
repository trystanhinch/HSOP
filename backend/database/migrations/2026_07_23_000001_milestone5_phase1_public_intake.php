<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('intake_sessions')) {
            Schema::create('intake_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('session_token', 64)->unique();
                $table->json('conversation_state')->nullable();
                $table->unsignedBigInteger('converted_lead_id')->nullable()->index();
                $table->dateTime('expires_at');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $table) {
                if (! Schema::hasColumn('leads', 'intake_channel')) {
                    $table->string('intake_channel', 40)->nullable()->after('source');
                }
                if (! Schema::hasColumn('leads', 'conversation_id')) {
                    $table->uuid('conversation_id')->nullable()->index()->after('intake_channel');
                }
            });
        }

        // Optional FKs — skip quietly if driver/constraints already present
        try {
            Schema::table('intake_sessions', function (Blueprint $table) {
                $table->foreign('converted_lead_id')->references('id')->on('leads')->nullOnDelete();
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreign('conversation_id')->references('id')->on('intake_sessions')->nullOnDelete();
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leads')) {
            try {
                Schema::table('leads', function (Blueprint $table) {
                    $table->dropForeign(['conversation_id']);
                });
            } catch (\Throwable) {
            }

            Schema::table('leads', function (Blueprint $table) {
                if (Schema::hasColumn('leads', 'conversation_id')) {
                    $table->dropColumn('conversation_id');
                }
                if (Schema::hasColumn('leads', 'intake_channel')) {
                    $table->dropColumn('intake_channel');
                }
            });
        }

        if (Schema::hasTable('intake_sessions')) {
            try {
                Schema::table('intake_sessions', function (Blueprint $table) {
                    $table->dropForeign(['converted_lead_id']);
                });
            } catch (\Throwable) {
            }
            Schema::dropIfExists('intake_sessions');
        }
    }
};
