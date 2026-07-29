<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A-35: lead duplicate grouping + soft merge/ignore + convert override audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leads')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'duplicate_group_id')) {
                $table->string('duplicate_group_id', 64)->nullable()->index()->after('needs_manual_review');
            }
            if (! Schema::hasColumn('leads', 'is_duplicate_primary')) {
                $table->boolean('is_duplicate_primary')->default(false)->after('duplicate_group_id');
            }
            if (! Schema::hasColumn('leads', 'merged_into_lead_id')) {
                $table->unsignedBigInteger('merged_into_lead_id')->nullable()->index()->after('is_duplicate_primary');
            }
            if (! Schema::hasColumn('leads', 'merged_at')) {
                $table->timestamp('merged_at')->nullable()->after('merged_into_lead_id');
            }
            if (! Schema::hasColumn('leads', 'ignored_at')) {
                $table->timestamp('ignored_at')->nullable()->after('merged_at');
            }
            if (! Schema::hasColumn('leads', 'ignore_reason')) {
                $table->string('ignore_reason', 500)->nullable()->after('ignored_at');
            }
            if (! Schema::hasColumn('leads', 'review_reason')) {
                $table->string('review_reason', 500)->nullable()->after('ignore_reason');
            }
            if (! Schema::hasColumn('leads', 'convert_override_by')) {
                $table->unsignedBigInteger('convert_override_by')->nullable()->after('review_reason');
            }
            if (! Schema::hasColumn('leads', 'convert_override_at')) {
                $table->timestamp('convert_override_at')->nullable()->after('convert_override_by');
            }
            if (! Schema::hasColumn('leads', 'convert_override_reason')) {
                $table->string('convert_override_reason', 500)->nullable()->after('convert_override_at');
            }
            if (! Schema::hasColumn('leads', 'contact_validated_at')) {
                $table->timestamp('contact_validated_at')->nullable()->after('convert_override_reason');
            }
        });

        if (! Schema::hasTable('lead_merge_logs')) {
            Schema::create('lead_merge_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('primary_lead_id');
                $table->json('merged_lead_ids');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('pre_merge_snapshot')->nullable();
                $table->json('field_choices')->nullable();
                $table->json('reassignment_counts')->nullable();
                $table->string('status', 40)->default('pending'); // pending|completed|failed
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->index('primary_lead_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_merge_logs');
        if (! Schema::hasTable('leads')) {
            return;
        }
        Schema::table('leads', function (Blueprint $table) {
            foreach ([
                'duplicate_group_id', 'is_duplicate_primary', 'merged_into_lead_id', 'merged_at',
                'ignored_at', 'ignore_reason', 'review_reason', 'convert_override_by',
                'convert_override_at', 'convert_override_reason', 'contact_validated_at',
            ] as $col) {
                if (Schema::hasColumn('leads', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
