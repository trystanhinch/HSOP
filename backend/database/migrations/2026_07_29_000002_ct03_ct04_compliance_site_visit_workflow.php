<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CT-04: site visit submission data (draft / submitted workflow).
        if (! Schema::hasTable('site_visit_submissions')) {
            Schema::create('site_visit_submissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_visit_id')->constrained()->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $table->foreignId('contractor_id')->comment('users.id');
                $table->string('status', 40)->default('draft'); // draft, submitted, revision_requested, revised
                $table->json('measurements')->nullable();
                $table->text('materials_notes')->nullable();
                $table->string('labour_estimate')->nullable();
                $table->string('crew_size')->nullable();
                $table->string('duration_estimate')->nullable();
                $table->text('assumptions')->nullable();
                $table->text('exclusions')->nullable();
                $table->decimal('contractor_price', 12, 2)->nullable();
                $table->text('price_notes')->nullable();
                $table->timestamp('price_submitted_at')->nullable();
                $table->timestamp('visit_completed_at')->nullable();
                $table->boolean('is_test_data')->default(false);
                $table->timestamps();

                $table->unique(['site_visit_id', 'contractor_id']);
            });
        }

        // CT-04: photos linked to site visit submissions.
        if (! Schema::hasTable('site_visit_photos')) {
            Schema::create('site_visit_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_visit_submission_id')->constrained('site_visit_submissions')->cascadeOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('uploaded_by')->constrained('users');
                $table->string('file_url');
                $table->string('file_name')->nullable();
                $table->string('caption')->nullable();
                $table->timestamps();
            });
        }

        // CT-04: expand status enum to support accept/decline workflow.
        \Illuminate\Support\Facades\DB::statement(
            "ALTER TABLE `site_visits` MODIFY `status` VARCHAR(40) NOT NULL DEFAULT 'scheduled'"
        );

        Schema::table('site_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('site_visits', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('site_visits', 'declined_at')) {
                $table->timestamp('declined_at')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('site_visits', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('declined_at');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visit_photos');
        Schema::dropIfExists('site_visit_submissions');

        if (Schema::hasTable('site_visits')) {
            Schema::table('site_visits', function (Blueprint $table) {
                foreach (['accepted_at', 'declined_at', 'completed_at'] as $col) {
                    if (Schema::hasColumn('site_visits', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
