<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('service_type')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('gst_number')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('contact_name')->nullable()->after('customer_id');
            $table->date('site_visit_date')->nullable()->after('status');
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->text('scope_of_work')->nullable()->after('address');
            $table->decimal('contractor_submitted_price', 10, 2)->nullable()->after('scope_of_work');
            $table->string('contractor_price_status')->default('pending')->after('contractor_submitted_price');
            $table->date('scheduled_start_date')->nullable()->after('contractor_price_status');
            $table->date('scheduled_end_date')->nullable()->after('scheduled_start_date');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->decimal('customer_price_before_gst', 10, 2)->nullable()->after('contractor_base_price');
            $table->decimal('hsop_markup', 10, 2)->nullable()->after('customer_price_before_gst');
            $table->string('customer_token')->nullable()->unique()->after('pdf_ref');
            $table->timestamp('sent_at')->nullable()->after('customer_token');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['customer_price_before_gst', 'hsop_markup', 'customer_token', 'sent_at']);
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn([
                'scope_of_work',
                'contractor_submitted_price',
                'contractor_price_status',
                'scheduled_start_date',
                'scheduled_end_date',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['contact_name', 'site_visit_date']);
        });

        Schema::dropIfExists('companies');
    }
};
