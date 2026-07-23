<?php

namespace App\Services\Learning;

use App\Models\EstimateOutcome;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payout;
use App\Models\PricingOverrideLog;
use App\Models\Quote;
use App\Models\ReviewFeedback;
use App\Models\RevisionRequest;

/**
 * Assembles a Learning Centre–ready picture for one Lead/Job.
 *
 * Intentionally read-only join/assembly — no AI, no recommendations.
 * Estimate history lives in estimate_outcomes (versioned). Lead.price_estimate_*
 * columns remain the current-pointer for existing UI.
 */
class JobEstimateSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function forJob(Job $job): array
    {
        $job->loadMissing([
            'lead.photos',
            'lead.brand',
            'lead.companySource',
            'lead.estimateOutcomes',
            'lead.currentEstimateOutcome',
            'quote',
            'invoice',
            'payouts',
            'contractor:id,name,email',
            'pm:id,name,email',
            'customer:id,name,email,phone',
            'revisionRequests',
            'reviewFeedback',
            'updates.photos',
        ]);

        $lead = $job->lead;

        return $this->assemble(
            lead: $lead,
            job: $job,
            quote: $job->quote,
            invoice: $job->invoice,
        );
    }

    /**
     * Pre-job (Lead only) assembly — same shape, job section null.
     *
     * @return array<string, mixed>
     */
    public function forLead(Lead $lead): array
    {
        $lead->loadMissing(['photos', 'brand', 'companySource', 'estimateOutcomes', 'currentEstimateOutcome']);

        $job = Job::query()->where('lead_id', $lead->id)->latest('id')->first();
        if ($job) {
            return $this->forJob($job);
        }

        $quote = Quote::query()->where('lead_id', $lead->id)->latest('id')->first();

        return $this->assemble(lead: $lead, job: null, quote: $quote, invoice: null);
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(?Lead $lead, ?Job $job, ?Quote $quote, ?Invoice $invoice): array
    {
        $currentOutcome = $lead?->currentEstimateOutcome
            ?? ($lead ? EstimateOutcome::query()->where('lead_id', $lead->id)->where('is_current', true)->first() : null);

        $estimate = $currentOutcome?->reasoning_snapshot
            ?? $lead?->price_estimate_snapshot
            ?? ($lead?->parse_metadata['price_estimate'] ?? null);

        $versions = $lead
            ? EstimateOutcome::query()
                ->where('lead_id', $lead->id)
                ->orderBy('version')
                ->get()
            : collect();

        $overrides = PricingOverrideLog::query()
            ->where(function ($q) use ($lead, $job) {
                if ($lead) {
                    $q->where('lead_id', $lead->id);
                }
                if ($job) {
                    $q->orWhere('job_id', $job->id);
                }
                if ($lead?->brand_id) {
                    $q->orWhere(function ($inner) use ($lead) {
                        $inner->where('subject_type', 'pricing_rule')
                            ->where('brand_id', $lead->brand_id);
                    });
                }
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $payouts = $job
            ? $job->payouts ?? Payout::query()->where('job_id', $job->id)->get()
            : collect();

        $revisions = $job
            ? ($job->revisionRequests ?? RevisionRequest::query()->where('job_id', $job->id)->get())
            : collect();

        $review = $job
            ? ($job->reviewFeedback ?? ReviewFeedback::query()->where('job_id', $job->id)->first())
            : null;

        $jobPhotos = [];
        if ($job) {
            foreach ($job->updates ?? [] as $update) {
                foreach ($update->photos ?? [] as $photo) {
                    $jobPhotos[] = [
                        'source' => 'job_update',
                        'job_update_id' => $update->id,
                        'file_url' => $photo->file_url,
                        'file_name' => $photo->file_name,
                    ];
                }
            }
        }

        return [
            'assembled_at' => now()->toIso8601String(),
            'purpose' => 'learning_centre_foundation_v1',
            'ai_learning' => false, // capture/assembly only — no recommendations

            // --- Referenced (existing) ---
            'intake' => [
                'lead_id' => $lead?->id,
                'brand_id' => $lead?->brand_id,
                'company_source_id' => $lead?->company_source_id,
                'service_category' => $lead?->service_category ?? $job?->service_category,
                'project_description' => $lead?->project_description,
                'scope_of_work' => $job?->scope_of_work,
                'address' => $job?->address ?? $lead?->address,
                'intake_channel' => $lead?->intake_channel,
                'conversation_id' => $lead?->conversation_id,
                'parse_metadata' => $lead?->parse_metadata, // includes transcript / collected_fields
                'lead_photos' => ($lead?->photos ?? collect())->map(fn ($p) => [
                    'id' => $p->id,
                    'file_url' => $p->file_url,
                ])->values()->all(),
            ],

            'estimate' => [
                'estimate_group_id' => $currentOutcome?->estimate_group_id
                    ?? ($lead?->parse_metadata['estimate_group_id'] ?? null),
                'current_outcome_id' => $currentOutcome?->id,
                'current_version' => $currentOutcome?->version,
                'low' => $currentOutcome?->price_low ?? $lead?->price_estimate_low,
                'high' => $currentOutcome?->price_high ?? $lead?->price_estimate_high,
                'confidence' => $currentOutcome?->confidence
                    ?? (is_array($estimate) ? ($estimate['confidence'] ?? null) : null),
                'service_category' => $currentOutcome?->service_category
                    ?? $lead?->service_category
                    ?? $job?->service_category,
                'ai_provider' => $currentOutcome?->ai_provider,
                'ai_model' => $currentOutcome?->ai_model,
                'ai_model_version' => $currentOutcome?->ai_model_version,
                'estimator_engine' => $currentOutcome?->estimator_engine,
                'estimated_at' => optional($currentOutcome?->estimated_at)?->toIso8601String(),
                'embedding_vector' => $currentOutcome?->embedding_vector, // reserved; always null in M5
                'snapshot' => $estimate,
                'materials_assumptions' => $currentOutcome?->materials_assumptions
                    ?? (is_array($estimate) ? ($estimate['materials_assumptions'] ?? null) : null),
                'labour_assumptions' => $currentOutcome?->labour_assumptions
                    ?? (is_array($estimate) ? ($estimate['labour_assumptions'] ?? null) : null),
                'versions' => $versions->map(fn (EstimateOutcome $v) => [
                    'id' => $v->id,
                    'estimate_group_id' => $v->estimate_group_id,
                    'version' => $v->version,
                    'source_kind' => $v->source_kind,
                    'service_category' => $v->service_category,
                    'price_low' => $v->price_low,
                    'price_high' => $v->price_high,
                    'confidence' => $v->confidence,
                    'is_current' => $v->is_current,
                    'ai_provider' => $v->ai_provider,
                    'ai_model' => $v->ai_model,
                    'ai_model_version' => $v->ai_model_version,
                    'estimator_engine' => $v->estimator_engine,
                    'estimated_at' => optional($v->estimated_at)?->toIso8601String(),
                    'actor_id' => $v->actor_id,
                    'reason' => $v->reason,
                    'supersedes_id' => $v->supersedes_id,
                    'embedding_vector' => $v->embedding_vector,
                ])->values()->all(),
            ],

            'quote_final_price' => $quote ? [
                'quote_id' => $quote->id,
                'contractor_base_price' => $quote->contractor_base_price,
                'subtotal' => $quote->subtotal,
                'customer_total' => $quote->customer_total,
                'pm_amount' => $quote->pm_amount,
                'company_amount' => $quote->company_amount,
                'status' => $quote->status ?? null,
            ] : null,

            'contractor' => [
                'contractor_id' => $job?->contractor_id,
                'contractor_name' => $job?->contractor?->name,
                'pm_id' => $job?->pm_id,
                'pm_name' => $job?->pm?->name,
            ],

            'actuals_costs_profit' => [
                'invoice' => $invoice ? [
                    'invoice_id' => $invoice->id,
                    'status' => $invoice->status,
                    'subtotal' => $invoice->subtotal ?? null,
                    'gst' => $invoice->gst ?? null,
                    'amount' => $invoice->amount ?? null,
                    'total' => $invoice->total ?? $invoice->amount ?? null,
                    'payment_date' => $invoice->payment_date ?? null,
                ] : null,
                'payouts' => $payouts->map(fn ($p) => [
                    'id' => $p->id,
                    'payout_type' => $p->payout_type,
                    'payout_amount' => $p->payout_amount,
                    'status' => $p->status,
                    'eligibility_status' => $p->eligibility_status ?? null,
                ])->values()->all(),
            ],

            'customer_feedback' => $review ? [
                'id' => $review->id,
                'rating' => $review->star_rating ?? $review->rating ?? null,
                'comment' => $review->comment ?? null,
                'issue_category' => $review->issue_category ?? null,
                'google_review_shown' => $review->google_review_shown ?? null,
                'submitted_at' => optional($review->submitted_at)?->toIso8601String(),
            ] : null,

            'revisions' => $revisions->map(fn ($r) => [
                'id' => $r->id,
                'description' => $r->description,
                'status' => $r->status,
                'created_at' => optional($r->created_at)?->toIso8601String(),
            ])->values()->all(),

            'job_photos' => $jobPhotos,

            // --- NEW physical fields ---
            'actual_labour_hours' => $job?->actual_labour_hours !== null
                ? (float) $job->actual_labour_hours
                : null,
            'materials_used_actual' => $job?->materials_used,
            'owner_overrides' => $overrides->map(fn ($o) => [
                'id' => $o->id,
                'override_kind' => $o->override_kind,
                'subject_type' => $o->subject_type,
                'subject_id' => $o->subject_id,
                'actor_id' => $o->actor_id,
                'reason' => $o->reason,
                'before' => $o->before_json,
                'after' => $o->after_json,
                'created_at' => optional($o->created_at)?->toIso8601String(),
            ])->values()->all(),

            'job' => $job ? [
                'id' => $job->id,
                'status' => $job->status,
                'completed_at' => optional($job->completed_at)?->toIso8601String(),
                'customer_accepted_completion_at' => optional($job->customer_accepted_completion_at)?->toIso8601String(),
            ] : null,
        ];
    }
}
