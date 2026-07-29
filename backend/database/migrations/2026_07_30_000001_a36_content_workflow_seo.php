<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-36 — Agency-safe content workflow, revisions, technical SEO, brand assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_editor_brand_assignments')) {
            Schema::create('content_editor_brand_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('brand_id')->index();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'brand_id']);
            });
        }

        if (! Schema::hasTable('content_revisions')) {
            Schema::create('content_revisions', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 80)->index();
                $table->unsignedBigInteger('subject_id')->index();
                $table->unsignedInteger('revision_number')->default(1);
                $table->json('snapshot');
                $table->string('status_at_revision', 40)->nullable();
                $table->unsignedBigInteger('author_id')->nullable()->index();
                $table->unsignedBigInteger('reviewer_id')->nullable()->index();
                $table->string('action', 60);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('brand_redirects')) {
            Schema::create('brand_redirects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('brand_id')->index();
                $table->string('from_path', 255);
                $table->string('to_path', 255);
                $table->unsignedSmallInteger('status_code')->default(301);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['brand_id', 'from_path']);
            });
        }

        $this->addPageSeoColumns('location_pages');
        $this->addPageSeoColumns('brand_pages');
    }

    private function addPageSeoColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'sections')) {
                $blueprint->json('sections')->nullable()->after('content');
            }
            if (! Schema::hasColumn($table, 'canonical_url')) {
                $blueprint->string('canonical_url', 2048)->nullable();
            }
            if (! Schema::hasColumn($table, 'schema_markup')) {
                $blueprint->json('schema_markup')->nullable();
            }
            if (! Schema::hasColumn($table, 'sitemap_include')) {
                $blueprint->boolean('sitemap_include')->default(true);
            }
            if (! Schema::hasColumn($table, 'robots_noindex')) {
                $blueprint->boolean('robots_noindex')->default(false);
            }
            if (! Schema::hasColumn($table, 'og_image')) {
                $blueprint->string('og_image', 2048)->nullable();
            }
            if (! Schema::hasColumn($table, 'image_meta')) {
                $blueprint->json('image_meta')->nullable(); // alt, focal_x, focal_y
            }
            if (! Schema::hasColumn($table, 'scheduled_at')) {
                $blueprint->timestamp('scheduled_at')->nullable();
            }
            if (! Schema::hasColumn($table, 'published_at')) {
                $blueprint->timestamp('published_at')->nullable();
            }
            if (! Schema::hasColumn($table, 'author_id')) {
                $blueprint->unsignedBigInteger('author_id')->nullable();
            }
            if (! Schema::hasColumn($table, 'reviewer_id')) {
                $blueprint->unsignedBigInteger('reviewer_id')->nullable();
            }
            if (! Schema::hasColumn($table, 'revision_number')) {
                $blueprint->unsignedInteger('revision_number')->default(1);
            }
            if (! Schema::hasColumn($table, 'approved_at')) {
                $blueprint->timestamp('approved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_redirects');
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_editor_brand_assignments');

        foreach (['location_pages', 'brand_pages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach ([
                    'sections', 'canonical_url', 'schema_markup', 'sitemap_include',
                    'robots_noindex', 'og_image', 'image_meta', 'scheduled_at',
                    'published_at', 'author_id', 'reviewer_id', 'revision_number', 'approved_at',
                ] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $blueprint->dropColumn($col);
                    }
                }
            });
        }
    }
};
