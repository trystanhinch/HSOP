<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('operating_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('services')->nullable();
            $table->json('cities')->nullable();
            $table->string('wcb_status')->nullable();
            $table->string('liability_insurance_status')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'suspended'])->default('pending');
            $table->json('payment_info')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractors');
    }
};
