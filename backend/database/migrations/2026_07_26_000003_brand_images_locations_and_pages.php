<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 lean CMS: brand image slots, location pages, custom pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'images')) {
                $table->json('images')->nullable()->after('content');
            }
        });

        if (! Schema::hasTable('location_pages')) {
            Schema::create('location_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('slug', 160);
                $table->string('city_name', 160);
                $table->string('region', 160)->nullable();
                $table->json('content')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('status', 20)->default('draft'); // draft|published
                $table->timestamps();

                $table->unique(['brand_id', 'slug']);
                $table->index(['brand_id', 'status']);
            });
        }

        if (! Schema::hasTable('brand_pages')) {
            Schema::create('brand_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('slug', 160);
                $table->string('title', 255);
                $table->string('template_type', 40); // simple|home|service|quote
                $table->json('content')->nullable();
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->string('og_image')->nullable();
                $table->string('status', 20)->default('draft'); // draft|published
                $table->string('source_key', 160)->nullable(); // e.g. system:home, system:service:drywall_paint, page:12
                $table->timestamps();

                $table->unique(['brand_id', 'slug']);
                $table->index(['brand_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_pages');
        Schema::dropIfExists('location_pages');

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'images')) {
                $table->dropColumn('images');
            }
        });
    }
};
