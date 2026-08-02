<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 6A.4 — Owner-visible alerts ledger (AlertDispatcher channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alerts')) {
            return;
        }

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('severity', 32);
            $table->string('message', 1000);
            $table->json('context')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
