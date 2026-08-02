<?php

namespace App\Services\Learning;

use App\Models\ContractorPerformanceEvent;
use App\Models\EstimateOutcome;
use App\Models\Job;
use App\Models\LearningRecord;
use App\Models\Message;
use App\Models\PricingOverrideLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Milestone 6B Phase 5 — assemble a canonical learning_records row from source tables.
 *
 * Source tables remain authoritative. This is a derived, versioned snapshot:
 * each reassembly appends a new version (is_current flipped) so evaluation/retrieval
 * can see what the system believed at each assembly time without data loss.
 *
 * Distinct from Phase 4 LearningNormalizedRecord (AI draft workspace).
 */
class LearningRecordAssemblyService
{
    public const PROVENANCE_TYPES = [
        'customer-stated',
        'plan-derived',
        'photo-derived',
        'site-measured',
        'contractor-stated',
        'imported',
        'AI-derived',
        'owner-verified',
    ];

    public function __construct(private PropertyAddressParser $addresses) {}

    public function assembleForJob(int $jobId, bool $ensureProperty = true): LearningRecord
    {
        $job = Job::query()->with([
            'lead.photos',
            'lead.brand',
            'customer:id,name,email,phone',
            'contractor:id,name,email',
            'pm:id,name,email',
            'quote',
            'invoice',
            'updates.photos',
            'files',
            'revisionRequests',
            'reviewFeedback',
            'payouts',
            'property.region',
        ])->find($jobId);

        if (! $job) {
            throw new InvalidArgumentException("Job {$jobId} not found.");
        }

        return DB::transaction(function () use ($job, $ensureProperty) {
            if ($ensureProperty && ! $job->property_id) {
                $raw = $job->address ?: $job->lead?->address;
                $property = $this->addresses->resolveProperty($raw);
                if ($property) {
                    $job->forceFill(['property_id' => $property->id])->save();
                    if ($job->lead_id && ! $job->lead?->property_id) {
                        $job->lead?->forceFill(['property_id' => $property->id])->save();
                    }
                    $job->load('property.region');
                }
            }

            $payload = [];
            $provenance = [];
            $links = [];
            $missing = [];

            $this->put($payload, $provenance, 'job_id', $job->id, 'jobs', $job->id, $job->updated_at, 'imported');
            $this->put($payload, $provenance, 'job_status', $job->status, 'jobs', $job->id, $job->updated_at, 'imported');
            $this->put($payload, $provenance, 'service_category', $job->service_category, 'jobs', $job->id, $job->updated_at, 'imported');
            $this->put($payload, $provenance, 'scope_of_work', $job->scope_of_work, 'jobs', $job->id, $job->updated_at, 'plan-derived');
            $this->put($payload, $provenance, 'address_raw', $job->address, 'jobs', $job->id, $job->updated_at, 'customer-stated');
            $this->put($payload, $provenance, 'actual_labour_hours', $job->actual_labour_hours, 'jobs', $job->id, $job->updated_at, 'contractor-stated');
            $this->put($payload, $provenance, 'materials_used', $job->materials_used, 'jobs', $job->id, $job->updated_at, 'contractor-stated');
            $this->put($payload, $provenance, 'completed_at', optional($job->completed_at)?->toIso8601String(), 'jobs', $job->id, $job->completed_at, 'contractor-stated');

            if ($job->lead) {
                $lead = $job->lead;
                $this->put($payload, $provenance, 'lead_id', $lead->id, 'leads', $lead->id, $lead->updated_at, 'imported');
                $this->put($payload, $provenance, 'lead_contact_name', $lead->contact_name, 'leads', $lead->id, $lead->updated_at, 'customer-stated');
                $this->put($payload, $provenance, 'lead_phone', $lead->phone, 'leads', $lead->id, $lead->updated_at, 'customer-stated');
                $this->put($payload, $provenance, 'lead_email', $lead->email, 'leads', $lead->id, $lead->updated_at, 'customer-stated');
                $this->put($payload, $provenance, 'brand_id', $lead->brand_id, 'leads', $lead->id, $lead->updated_at, 'imported');
                $links['lead_photo_ids'] = $lead->photos->pluck('id')->all();
                if ($lead->photos->isEmpty()) {
                    $missing[] = 'lead_photos';
                }
            } else {
                $missing[] = 'lead';
            }

            if ($job->customer_id) {
                $this->put($payload, $provenance, 'customer_id', $job->customer_id, 'users', $job->customer_id, $job->customer?->updated_at, 'imported');
                $this->put($payload, $provenance, 'customer_name', $job->customer?->name, 'users', $job->customer_id, $job->customer?->updated_at, 'customer-stated');
            } else {
                $missing[] = 'customer';
            }

            if ($job->contractor_id) {
                $this->put($payload, $provenance, 'contractor_id', $job->contractor_id, 'users', $job->contractor_id, $job->contractor?->updated_at, 'imported');
            } else {
                $missing[] = 'contractor';
            }

            if ($job->pm_id) {
                $this->put($payload, $provenance, 'pm_id', $job->pm_id, 'users', $job->pm_id, $job->pm?->updated_at, 'imported');
            } else {
                $missing[] = 'pm';
            }

            $estimates = EstimateOutcome::query()
                ->where(function ($q) use ($job) {
                    $q->where('job_id', $job->id);
                    if ($job->lead_id) {
                        $q->orWhere('lead_id', $job->lead_id);
                    }
                })
                ->orderBy('version')
                ->get();

            $links['estimate_outcome_ids'] = $estimates->pluck('id')->all();
            $currentEstimate = $estimates->firstWhere('is_current', true) ?? $estimates->last();
            if ($currentEstimate) {
                $this->put($payload, $provenance, 'estimate_price_low', $currentEstimate->price_low, 'estimate_outcomes', $currentEstimate->id, $currentEstimate->updated_at, 'AI-derived');
                $this->put($payload, $provenance, 'estimate_price_high', $currentEstimate->price_high, 'estimate_outcomes', $currentEstimate->id, $currentEstimate->updated_at, 'AI-derived');
                $this->put($payload, $provenance, 'estimate_is_placeholder', (bool) $currentEstimate->is_placeholder, 'estimate_outcomes', $currentEstimate->id, $currentEstimate->updated_at, 'imported');
            } else {
                $missing[] = 'estimate_outcomes';
            }

            if ($job->quote) {
                $q = $job->quote;
                $this->put($payload, $provenance, 'quote_id', $q->id, 'quotes', $q->id, $q->updated_at, 'imported');
                $this->put($payload, $provenance, 'quote_status', $q->status, 'quotes', $q->id, $q->updated_at, 'imported');
                $this->put($payload, $provenance, 'quote_customer_total', $q->customer_total ?? $q->amount ?? null, 'quotes', $q->id, $q->updated_at, 'plan-derived');
            } else {
                $missing[] = 'quote';
            }

            if ($job->invoice) {
                $inv = $job->invoice;
                $this->put($payload, $provenance, 'invoice_id', $inv->id, 'invoices', $inv->id, $inv->updated_at, 'imported');
                $this->put($payload, $provenance, 'invoice_status', $inv->status, 'invoices', $inv->id, $inv->updated_at, 'imported');
                $this->put($payload, $provenance, 'invoice_amount', $inv->amount, 'invoices', $inv->id, $inv->updated_at, 'imported');
                $this->put($payload, $provenance, 'invoice_amount_paid', $inv->amount_paid ?? null, 'invoices', $inv->id, $inv->updated_at, 'imported');
            } else {
                $missing[] = 'invoice';
            }

            $messageIds = Message::query()->where('job_id', $job->id)->pluck('id')->all();
            $links['message_ids'] = $messageIds;
            if ($messageIds === []) {
                $missing[] = 'messages';
            }

            $updatePhotoIds = [];
            foreach ($job->updates as $update) {
                foreach ($update->photos as $photo) {
                    $updatePhotoIds[] = $photo->id;
                }
            }
            $links['job_update_photo_ids'] = $updatePhotoIds;
            $links['file_ids'] = $job->files->pluck('id')->all();
            if ($updatePhotoIds === [] && $job->files->isEmpty()) {
                $missing[] = 'job_files_photos';
            }

            $overrideIds = PricingOverrideLog::query()
                ->where(function ($q) use ($job) {
                    $q->where('job_id', $job->id);
                    if ($job->lead_id) {
                        $q->orWhere('lead_id', $job->lead_id);
                    }
                })
                ->pluck('id')
                ->all();
            $links['pricing_override_log_ids'] = $overrideIds;

            $perfIds = ContractorPerformanceEvent::query()
                ->where('job_id', $job->id)
                ->pluck('id')
                ->all();
            $links['contractor_performance_event_ids'] = $perfIds;

            $links['revision_request_ids'] = $job->revisionRequests->pluck('id')->all();
            $links['payout_ids'] = $job->payouts->pluck('id')->all();
            if ($job->reviewFeedback) {
                $links['review_feedback_id'] = $job->reviewFeedback->id;
                $this->put(
                    $payload,
                    $provenance,
                    'customer_rating',
                    $job->reviewFeedback->star_rating ?? null,
                    'review_feedback',
                    $job->reviewFeedback->id,
                    $job->reviewFeedback->updated_at ?? $job->reviewFeedback->created_at,
                    'customer-stated'
                );
            } else {
                $missing[] = 'review_feedback';
            }

            $property = $job->property;
            if ($property) {
                $this->put($payload, $provenance, 'property_id', $property->id, 'properties', $property->id, $property->updated_at, 'imported');
                $this->put($payload, $provenance, 'property_city', $property->city, 'properties', $property->id, $property->updated_at, 'imported');
                $this->put($payload, $provenance, 'property_postal_code', $property->postal_code, 'properties', $property->id, $property->updated_at, 'imported');
                $this->put($payload, $provenance, 'property_type', $property->property_type, 'properties', $property->id, $property->updated_at, 'imported');
                $this->put($payload, $provenance, 'region_id', $property->region_id, 'properties', $property->id, $property->updated_at, 'imported');
            } else {
                $missing[] = 'property';
            }

            // Eligibility pointer — Phase 3 source of truth (prefer current estimate, else job)
            if ($currentEstimate) {
                $eligType = 'estimate_outcome';
                $eligId = $currentEstimate->id;
                $eligSnapshot = $currentEstimate->learning_eligibility_status;
            } else {
                $eligType = 'job';
                $eligId = $job->id;
                $eligSnapshot = $job->learning_eligibility_status;
            }
            $this->put(
                $payload,
                $provenance,
                'learning_eligibility_status',
                $eligSnapshot,
                $eligType === 'job' ? 'jobs' : 'estimate_outcomes',
                $eligId,
                now(),
                'owner-verified'
            );

            $prior = LearningRecord::query()
                ->where('job_id', $job->id)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            $groupId = $prior?->record_group_id ?? (string) Str::uuid();
            $version = $prior ? ((int) $prior->version + 1) : 1;

            if ($prior) {
                LearningRecord::query()
                    ->where('job_id', $job->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            return LearningRecord::create([
                'record_group_id' => $groupId,
                'version' => $version,
                'is_current' => true,
                'job_id' => $job->id,
                'lead_id' => $job->lead_id,
                'property_id' => $job->property_id,
                'region_id' => $property?->region_id,
                'customer_id' => $job->customer_id,
                'contractor_id' => $job->contractor_id,
                'pm_id' => $job->pm_id,
                'quote_id' => $job->quote?->id,
                'invoice_id' => $job->invoice?->id,
                'current_estimate_outcome_id' => $currentEstimate?->id,
                'eligibility_source_type' => $eligType,
                'eligibility_source_id' => $eligId,
                'eligibility_status_snapshot' => $eligSnapshot,
                'payload' => $payload,
                'provenance' => $provenance,
                'links' => $links,
                'missing_sources' => array_values(array_unique($missing)),
                'assembled_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provenance
     */
    private function put(
        array &$payload,
        array &$provenance,
        string $field,
        mixed $value,
        string $sourceTable,
        int|string|null $sourceId,
        mixed $timestamp,
        string $provenanceType,
    ): void {
        if ($value === null || $value === '') {
            return;
        }
        if (! in_array($provenanceType, self::PROVENANCE_TYPES, true)) {
            $provenanceType = 'imported';
        }

        $payload[$field] = $value;
        $provenance[$field] = [
            'source_table' => $sourceTable,
            'source_id' => $sourceId,
            'source_timestamp' => $this->ts($timestamp),
            'provenance_type' => $provenanceType,
        ];
    }

    private function ts(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->format(\DateTimeInterface::ATOM);
        }

        return (string) $timestamp;
    }
}
