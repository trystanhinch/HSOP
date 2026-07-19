<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lead statuses — extend ENUM (keep legacy values)
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE leads MODIFY COLUMN status ENUM(
                'new','duplicate_review','pm_assigned','customer_contacted','call_scheduled',
                'site_visit_scheduled','converted','disqualified',
                'contacted','quote_needed','lost'
            ) NOT NULL DEFAULT 'new'");

            DB::statement("ALTER TABLE quotes MODIFY COLUMN status ENUM(
                'pricing_requested','pricing_received','draft','sent','viewed','follow_up',
                'approved','declined','expired','rejected','revised'
            ) NOT NULL DEFAULT 'draft'");

            DB::statement("ALTER TABLE jobs MODIFY COLUMN status ENUM(
                'created','waiting_to_schedule','scheduled','in_progress','update_posted',
                'completion_requested','revision_requested','revision_in_progress',
                'completion_accepted','closed',
                'new_job','contractor_assigned','site_visit_scheduled','site_visit_completed',
                'contractor_pricing_pending','quote_sent','estimate_sent','quote_approved',
                'estimate_accepted','start_date_scheduled','progress_updated','waiting_on_customer',
                'ready_for_review','pending_customer_approval','corrections_required',
                'completed_by_contractor','final_review','completed','payment_pending',
                'etransfer_pending_confirmation','paid_completed','invoiced','paid','cancelled'
            ) NOT NULL DEFAULT 'new_job'");

            DB::statement("ALTER TABLE payouts MODIFY COLUMN status ENUM(
                'not_eligible','waiting_for_completion_acceptance','waiting_for_payment',
                'waiting_for_revision_closure','eligible','scheduled','pending','in_transit',
                'paid','failed','on_hold',
                'not_ready','ready_for_payout','approved','hold_issue'
            ) NOT NULL DEFAULT 'not_ready'");
        }

        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('status')->nullable()->after('method');
            });
        }

        if (! Schema::hasTable('workflow_reviews')) {
            Schema::create('workflow_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->unsignedTinyInteger('internal_rating')->nullable();
                $table->text('internal_notes')->nullable();
                $table->boolean('google_link_shown')->default(false);
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('message_templates')) {
            Schema::create('message_templates', function (Blueprint $table) {
                $table->id();
                $table->string('event_key')->unique();
                $table->string('channel')->default('sms'); // sms|email|both
                $table->string('label');
                $table->text('body');
                $table->json('variables')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('workflow_escalation_logs')) {
            Schema::create('workflow_escalation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('next_action_id')->constrained('next_actions')->cascadeOnDelete();
                $table->string('rule_key');
                $table->string('stage'); // reminder|escalation|follow_up
                $table->timestamp('fired_at');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['next_action_id', 'rule_key', 'stage']);
            });
        }

        // Seed default workflow thresholds if missing
        $defaults = [
            'workflow_pm_contact_lead_hours' => '4',
            'workflow_pm_contact_escalation_hours' => '4',
            'workflow_contractor_pricing_deadline_hours' => '24',
            'workflow_quote_follow_up_hours' => '48',
            'workflow_job_missing_update_days' => '7',
            'ai_mode_escalations' => 'assisted',
        ];

        foreach ($defaults as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();
            if (! $exists) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_escalation_logs');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('workflow_reviews');

        if (Schema::hasColumn('payments', 'status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
