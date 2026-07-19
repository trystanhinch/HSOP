<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gmail_oauth_tokens')) {
            Schema::create('gmail_oauth_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('mailbox_email')->unique();
                $table->text('access_token_encrypted')->nullable();
                $table->text('refresh_token_encrypted')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->string('scope')->nullable();
                $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_fetched_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('gmail_processed_messages')) {
            Schema::create('gmail_processed_messages', function (Blueprint $table) {
                $table->id();
                $table->string('gmail_message_id')->unique();
                $table->string('gmail_thread_id')->nullable();
                $table->string('mailbox_email');
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->string('status')->default('processed'); // processed | skipped_duplicate | failed
                $table->text('error')->nullable();
                $table->timestamp('processed_at');
                $table->timestamps();

                $table->index(['mailbox_email', 'processed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_processed_messages');
        Schema::dropIfExists('gmail_oauth_tokens');
    }
};
