<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2)->nullable();
            $table->enum('method', ['e_transfer'])->default('e_transfer');
            $table->boolean('paid_status')->default(false);
            $table->boolean('cleared_status')->default(false);
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('paid_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
