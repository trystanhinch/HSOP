<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_rules')) {
            Schema::create('pricing_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                // Optional ops link — brand is the primary scope for public estimates
                $table->foreignId('company_source_id')->nullable()->constrained('company_sources')->nullOnDelete();
                $table->string('service_category', 80)->index();
                $table->string('rule_type', 40)->default('per_sqft'); // per_sqft | flat | tiered
                $table->decimal('base_rate', 10, 4)->nullable(); // primary rate (e.g. $/sqft mid, or flat base)
                $table->json('size_tiers')->nullable();
                $table->json('complexity_modifiers')->nullable();
                $table->decimal('min_price', 12, 2)->nullable();
                $table->decimal('max_price', 12, 2)->nullable();
                $table->string('currency', 3)->default('CAD');
                $table->string('status', 20)->default('active')->index(); // active | draft | archived
                $table->boolean('is_placeholder')->default(true); // true until Trystan confirms rates
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['brand_id', 'service_category', 'status'], 'pricing_rules_brand_cat_status_idx');
            });
        }

        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $table) {
                if (! Schema::hasColumn('leads', 'price_estimate_low')) {
                    $table->decimal('price_estimate_low', 12, 2)->nullable()->after('contractor_price_notes');
                }
                if (! Schema::hasColumn('leads', 'price_estimate_high')) {
                    $table->decimal('price_estimate_high', 12, 2)->nullable()->after('price_estimate_low');
                }
                if (! Schema::hasColumn('leads', 'price_estimate_snapshot')) {
                    $table->json('price_estimate_snapshot')->nullable()->after('price_estimate_high');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leads')) {
            Schema::table('leads', function (Blueprint $table) {
                if (Schema::hasColumn('leads', 'price_estimate_snapshot')) {
                    $table->dropColumn('price_estimate_snapshot');
                }
                if (Schema::hasColumn('leads', 'price_estimate_high')) {
                    $table->dropColumn('price_estimate_high');
                }
                if (Schema::hasColumn('leads', 'price_estimate_low')) {
                    $table->dropColumn('price_estimate_low');
                }
            });
        }

        Schema::dropIfExists('pricing_rules');
    }
};
