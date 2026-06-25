<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->enum('service_category', ['drywall_paint', 'insulation'])->nullable();
            $table->string('source')->nullable();
            $table->string('company_listing')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_pm_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['new', 'site_visit_scheduled', 'quoted', 'converted', 'lost'])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
