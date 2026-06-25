<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('contractor_base_price', 10, 2)->nullable();
            $table->decimal('gst', 10, 2)->nullable();
            $table->decimal('customer_total', 10, 2)->nullable();
            $table->enum('status', ['draft', 'sent', 'accepted', 'declined'])->default('draft');
            $table->string('pdf_ref')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
