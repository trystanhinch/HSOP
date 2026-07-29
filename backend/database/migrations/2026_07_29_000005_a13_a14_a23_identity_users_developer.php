<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-13 / A-14 / A-23 — company legal identity, user lifecycle, developer access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('name');
            }
            if (! Schema::hasColumn('companies', 'operating_name')) {
                $table->string('operating_name')->nullable()->after('legal_name');
            }
            if (! Schema::hasColumn('companies', 'remittance_address')) {
                $table->text('remittance_address')->nullable()->after('address');
            }
            if (! Schema::hasColumn('companies', 'province')) {
                $table->string('province', 64)->nullable()->after('remittance_address');
            }
            if (! Schema::hasColumn('companies', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('province');
            }
            if (! Schema::hasColumn('companies', 'currency')) {
                $table->string('currency', 8)->nullable()->after('timezone');
            }
            if (! Schema::hasColumn('companies', 'gst_verification_status')) {
                $table->string('gst_verification_status', 32)->nullable()->after('gst_number');
            }
            if (! Schema::hasColumn('companies', 'invoice_prefix')) {
                $table->string('invoice_prefix', 32)->nullable()->after('gst_verification_status');
            }
            if (! Schema::hasColumn('companies', 'public_contact_email')) {
                $table->string('public_contact_email')->nullable()->after('invoice_prefix');
            }
            if (! Schema::hasColumn('companies', 'public_contact_phone')) {
                $table->string('public_contact_phone', 32)->nullable()->after('public_contact_email');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'invited_at')) {
                $table->timestamp('invited_at')->nullable()->after('last_login_at');
            }
            if (! Schema::hasColumn('users', 'invitation_status')) {
                $table->string('invitation_status', 32)->nullable()->after('invited_at');
            }
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('invitation_status');
            }
            if (! Schema::hasColumn('users', 'is_developer')) {
                $table->boolean('is_developer')->default(false)->after('suspended_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach ([
                'legal_name', 'operating_name', 'remittance_address', 'province', 'timezone',
                'currency', 'gst_verification_status', 'invoice_prefix', 'public_contact_email',
                'public_contact_phone',
            ] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['last_login_at', 'invited_at', 'invitation_status', 'suspended_at', 'is_developer'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
