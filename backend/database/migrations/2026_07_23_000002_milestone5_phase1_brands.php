<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                $table->string('domain')->unique();
                $table->string('slug')->unique();
                $table->string('company_name');
                // Ops routing — which CompanySource / default PM this public site feeds
                $table->foreignId('company_source_id')->nullable()->constrained('company_sources')->nullOnDelete();
                // Ops: services this brand offers (keys + labels + optional keywords for intake)
                $table->json('service_categories')->nullable();
                // Branding / content foundation (future CMS scope — keep separate from ops)
                $table->json('branding')->nullable();
                $table->json('contact_info')->nullable();
                // SEO foundation defaults (page-level overrides come later)
                $table->json('seo_defaults')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('intake_sessions') && ! Schema::hasColumn('intake_sessions', 'brand_id')) {
            Schema::table('intake_sessions', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->after('id')->constrained('brands')->nullOnDelete();
            });
        }

        if (Schema::hasTable('leads') && ! Schema::hasColumn('leads', 'brand_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->after('company_source_id')->constrained('brands')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'brand_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->dropConstrainedForeignId('brand_id');
            });
        }

        if (Schema::hasTable('intake_sessions') && Schema::hasColumn('intake_sessions', 'brand_id')) {
            Schema::table('intake_sessions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('brand_id');
            });
        }

        Schema::dropIfExists('brands');
    }
};
