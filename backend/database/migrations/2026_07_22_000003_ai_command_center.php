<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_command_sessions')) {
            Schema::create('ai_command_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_command_messages')) {
            Schema::create('ai_command_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('session_id')->constrained('ai_command_sessions')->cascadeOnDelete();
                $table->string('role', 20); // user|assistant|system
                $table->longText('content');
                $table->json('meta')->nullable(); // tools, usage, pending_action, ai_action_log_id
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_command_messages');
        Schema::dropIfExists('ai_command_sessions');
    }
};
