<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('payout_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'paid'])->default('pending');
            $table->string('eligibility_status')->nullable();
            $table->date('paid_date')->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
