<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_listing')->nullable();
            $table->enum('service_category', ['drywall_paint', 'insulation'])->nullable();
            $table->text('address')->nullable();
            $table->enum('status', ['active', 'scheduled', 'in_progress', 'completed', 'cancelled'])->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
