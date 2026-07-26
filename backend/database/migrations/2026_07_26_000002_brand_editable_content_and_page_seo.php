<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 5 — editable versions of content that already exists publicly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'content')) {
                $table->json('content')->nullable()->after('seo_defaults');
            }
        });

        if (! Schema::hasTable('brand_page_seo_overrides')) {
            Schema::create('brand_page_seo_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('page_key', 120);
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('og_image')->nullable();
                $table->timestamps();

                $table->unique(['brand_id', 'page_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_page_seo_overrides');

        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'content')) {
                $table->dropColumn('content');
            }
        });
    }
};
