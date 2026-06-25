<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->date('wcb_expiry_date')->nullable()->after('wcb_status');
            $table->date('insurance_expiry_date')->nullable()->after('liability_insurance_status');
            $table->string('wcb_file_url')->nullable()->after('wcb_expiry_date');
            $table->string('insurance_file_url')->nullable()->after('insurance_expiry_date');
        });

        Schema::create('contractor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contractor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->enum('document_type', ['wcb', 'liability_insurance', 'other']);
            $table->string('file_name');
            $table->string('file_url');
            $table->string('file_size')->nullable();
            $table->date('expiry_date')->nullable();
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'expired'])->default('pending_review');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_documents');

        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn([
                'wcb_expiry_date',
                'insurance_expiry_date',
                'wcb_file_url',
                'insurance_file_url',
            ]);
        });
    }
};
