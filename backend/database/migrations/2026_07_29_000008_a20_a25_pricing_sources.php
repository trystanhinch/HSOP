<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-20 / A-25 — pricing setting versions + company source rule versioning/health fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_setting_versions')) {
            Schema::create('pricing_setting_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id')->nullable()->index();
                $table->date('effective_date')->index();
                $table->decimal('gst_rate', 8, 4)->default(5);
                $table->decimal('markup_divisor', 8, 4)->default(0.80);
                $table->decimal('split_contractor_pct', 8, 4)->default(80);
                $table->decimal('split_pm_pct', 8, 4)->default(10);
                $table->decimal('split_company_pct', 8, 4)->default(10);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('previous_values')->nullable();
                $table->string('change_reason')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('company_sources', function (Blueprint $table) {
            if (! Schema::hasColumn('company_sources', 'priority')) {
                $table->unsignedInteger('priority')->default(100)->after('status');
            }
            if (! Schema::hasColumn('company_sources', 'parser_type')) {
                $table->string('parser_type', 60)->default('lead_email_v1')->after('priority');
            }
            if (! Schema::hasColumn('company_sources', 'parser_version')) {
                $table->string('parser_version', 40)->default('1.0')->after('parser_type');
            }
            if (! Schema::hasColumn('company_sources', 'fallback_behavior')) {
                $table->string('fallback_behavior', 40)->default('category_then_quarantine')->after('parser_version');
            }
        });

        if (! Schema::hasTable('company_source_versions')) {
            Schema::create('company_source_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_source_id')->constrained('company_sources')->cascadeOnDelete();
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('previous_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('change_summary')->nullable();
                $table->timestamps();
                $table->index(['company_source_id', 'version']);
            });
        }

        Schema::table('intake_quarantine', function (Blueprint $table) {
            if (! Schema::hasColumn('intake_quarantine', 'matched_needle')) {
                $table->string('matched_needle', 255)->nullable()->after('company_source_id');
            }
            if (! Schema::hasColumn('intake_quarantine', 'match_method')) {
                $table->string('match_method', 60)->nullable()->after('matched_needle');
            }
        });
    }

    public function down(): void
    {
        Schema::table('intake_quarantine', function (Blueprint $table) {
            foreach (['matched_needle', 'match_method'] as $col) {
                if (Schema::hasColumn('intake_quarantine', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('company_source_versions');

        Schema::table('company_sources', function (Blueprint $table) {
            foreach (['priority', 'parser_type', 'parser_version', 'fallback_behavior'] as $col) {
                if (Schema::hasColumn('company_sources', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('pricing_setting_versions');
    }
};
