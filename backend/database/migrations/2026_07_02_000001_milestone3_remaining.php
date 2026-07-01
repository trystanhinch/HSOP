<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1A — leads: site visit contractor, notes, portal token
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'site_visit_contractor_id')) {
                $table->foreignId('site_visit_contractor_id')
                    ->nullable()
                    ->after('site_visit_time')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('leads', 'site_visit_notes')) {
                $table->text('site_visit_notes')->nullable()->after('site_visit_contractor_id');
            }
            if (! Schema::hasColumn('leads', 'customer_portal_token')) {
                $table->string('customer_portal_token')->nullable()->unique();
            }
        });

        // 1B — site_visits table
        if (! Schema::hasTable('site_visits')) {
            Schema::create('site_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pm_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('contractor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('visit_date');
                $table->time('visit_time');
                $table->text('notes')->nullable();
                $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
                $table->timestamps();
            });
        }

        // 1D + 1G — jobs: split fields, completion/payment fields
        Schema::table('jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('jobs', 'split_contractor_pct')) {
                $table->decimal('split_contractor_pct', 5, 2)->default(80.00);
            }
            if (! Schema::hasColumn('jobs', 'split_pm_pct')) {
                $table->decimal('split_pm_pct', 5, 2)->default(10.00);
            }
            if (! Schema::hasColumn('jobs', 'split_company_pct')) {
                $table->decimal('split_company_pct', 5, 2)->default(10.00);
            }
            if (! Schema::hasColumn('jobs', 'pending_customer_approval_at')) {
                $table->timestamp('pending_customer_approval_at')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'customer_accepted_completion_at')) {
                $table->timestamp('customer_accepted_completion_at')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'revision_description')) {
                $table->text('revision_description')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'payment_method')) {
                $table->enum('payment_method', ['e_transfer', 'stripe', 'other'])->nullable();
            }
            if (! Schema::hasColumn('jobs', 'payment_confirmed_at')) {
                $table->timestamp('payment_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('jobs', 'payment_confirmed_by')) {
                $table->foreignId('payment_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('jobs', 'payment_reference')) {
                $table->string('payment_reference')->nullable();
            }
        });

        // 1E — quotes: split fields
        Schema::table('quotes', function (Blueprint $table) {
            if (! Schema::hasColumn('quotes', 'contractor_pct')) {
                $table->decimal('contractor_pct', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('quotes', 'pm_pct')) {
                $table->decimal('pm_pct', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('quotes', 'company_pct')) {
                $table->decimal('company_pct', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('quotes', 'pm_amount')) {
                $table->decimal('pm_amount', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('quotes', 'company_amount')) {
                $table->decimal('company_amount', 10, 2)->nullable();
            }
        });

        // 1F — payouts: payout_type
        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'payout_type')) {
                $table->enum('payout_type', ['contractor', 'pm'])->default('contractor')->after('id');
            }
        });

        // 1H — revision_requests
        if (! Schema::hasTable('revision_requests')) {
            Schema::create('revision_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->text('description');
                $table->enum('status', ['open', 'resolved'])->default('open');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('revision_request_photos')) {
            Schema::create('revision_request_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('revision_request_id')->constrained('revision_requests')->cascadeOnDelete();
                $table->string('file_name');
                $table->string('file_url');
                $table->timestamps();
            });
        }

        // Expand job status enum (keep existing + add M3 statuses)
        DB::statement("ALTER TABLE jobs MODIFY COLUMN status ENUM(
            'new_job','contractor_assigned','site_visit_scheduled','site_visit_completed',
            'contractor_pricing_pending','quote_sent','estimate_sent','quote_approved','estimate_accepted',
            'scheduled','start_date_scheduled','in_progress','progress_updated','waiting_on_customer',
            'ready_for_review','pending_customer_approval','corrections_required','revision_requested',
            'completed_by_contractor','final_review','completed','payment_pending',
            'etransfer_pending_confirmation','paid_completed','invoiced','paid','cancelled'
        ) NOT NULL DEFAULT 'new_job'");
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_request_photos');
        Schema::dropIfExists('revision_requests');
        Schema::dropIfExists('site_visits');

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'site_visit_contractor_id')) {
                $table->dropForeign(['site_visit_contractor_id']);
                $table->dropColumn('site_visit_contractor_id');
            }
            if (Schema::hasColumn('leads', 'site_visit_notes')) {
                $table->dropColumn('site_visit_notes');
            }
            if (Schema::hasColumn('leads', 'customer_portal_token')) {
                $table->dropColumn('customer_portal_token');
            }
        });

        Schema::table('jobs', function (Blueprint $table) {
            $cols = [
                'split_contractor_pct', 'split_pm_pct', 'split_company_pct',
                'pending_customer_approval_at', 'customer_accepted_completion_at',
                'revision_description', 'payment_method', 'payment_confirmed_at',
                'payment_reference',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('jobs', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('jobs', 'payment_confirmed_by')) {
                $table->dropForeign(['payment_confirmed_by']);
                $table->dropColumn('payment_confirmed_by');
            }
        });

        Schema::table('quotes', function (Blueprint $table) {
            foreach (['contractor_pct', 'pm_pct', 'company_pct', 'pm_amount', 'company_amount'] as $col) {
                if (Schema::hasColumn('quotes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (Schema::hasColumn('payouts', 'payout_type')) {
                $table->dropColumn('payout_type');
            }
        });
    }
};
