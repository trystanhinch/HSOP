<?php

namespace App\Services\Leads;

use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\LeadMergeLog;
use App\Models\Message;
use App\Models\NextAction;
use App\Models\Quote;
use App\Models\SiteVisit;
use App\Models\SiteVisitPhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A-35 — Soft-merge leads with review + audit (mirrors A-33 CustomerMergeService).
 */
class LeadMergeService
{
    /**
     * @param  list<int>  $leadIds
     * @param  array<string, mixed>  $fieldChoices
     * @return array{primary: Lead, log: LeadMergeLog, counts: array<string, int>}
     */
    public function merge(array $leadIds, int $primaryLeadId, User $actor, array $fieldChoices = []): array
    {
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));
        if (count($leadIds) < 2) {
            throw new \InvalidArgumentException('Select at least two leads to merge.');
        }
        if (! in_array($primaryLeadId, $leadIds, true)) {
            throw new \InvalidArgumentException('Primary lead must be one of the selected records.');
        }

        $leads = Lead::withTestData()
            ->whereIn('id', $leadIds)
            ->whereNull('merged_into_lead_id')
            ->get()
            ->keyBy('id');

        if ($leads->count() !== count($leadIds)) {
            throw new \InvalidArgumentException('One or more leads were not found or already merged.');
        }

        $primary = $leads->get($primaryLeadId);
        $secondaries = $leads->except($primaryLeadId);

        $snapshot = $leads->map(fn (Lead $l) => [
            'id' => $l->id,
            'contact_name' => $l->contact_name,
            'phone' => $l->phone,
            'email' => $l->email,
            'address' => $l->address,
            'status' => $l->status,
            'duplicate_group_id' => $l->duplicate_group_id,
        ])->values()->all();

        $log = LeadMergeLog::create([
            'primary_lead_id' => $primary->id,
            'merged_lead_ids' => $secondaries->keys()->values()->all(),
            'actor_id' => $actor->id,
            'pre_merge_snapshot' => $snapshot,
            'field_choices' => $fieldChoices,
            'status' => 'pending',
        ]);

        try {
            $counts = DB::transaction(function () use ($primary, $secondaries, $fieldChoices) {
                $this->applyFieldChoices($primary, $secondaries, $fieldChoices);
                $counts = [
                    'jobs' => 0,
                    'quotes' => 0,
                    'messages' => 0,
                    'site_visits' => 0,
                    'site_visit_photos' => 0,
                    'next_actions' => 0,
                ];

                foreach ($secondaries as $secondary) {
                    $counts['jobs'] += Job::where('lead_id', $secondary->id)->update(['lead_id' => $primary->id]);
                    $counts['quotes'] += Quote::where('lead_id', $secondary->id)->update(['lead_id' => $primary->id]);
                    if (class_exists(SiteVisit::class)) {
                        $counts['site_visits'] += SiteVisit::where('lead_id', $secondary->id)->update(['lead_id' => $primary->id]);
                    }
                    $counts['site_visit_photos'] += SiteVisitPhoto::where('lead_id', $secondary->id)->update(['lead_id' => $primary->id]);
                    $counts['messages'] += Message::where('lead_id', $secondary->id)->update(['lead_id' => $primary->id]);
                    $counts['next_actions'] += NextAction::query()
                        ->where('subject_type', $secondary->getMorphClass())
                        ->where('subject_id', $secondary->id)
                        ->update([
                            'subject_type' => $primary->getMorphClass(),
                            'subject_id' => $primary->id,
                        ]);

                    $secondary->update([
                        'merged_into_lead_id' => $primary->id,
                        'merged_at' => now(),
                        'is_duplicate_primary' => false,
                        'status' => $secondary->status === 'converted' ? $secondary->status : 'lost',
                        'ignore_reason' => 'merged_into_'.$primary->id,
                    ]);
                }

                $primary->update([
                    'is_duplicate_primary' => true,
                    'merged_into_lead_id' => null,
                    'merged_at' => null,
                ]);

                return $counts;
            });

            $log->update([
                'status' => 'completed',
                'reassignment_counts' => $counts,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'user_role' => $actor->role,
                'object_type' => 'lead',
                'object_id' => $primary->id,
                'action_type' => 'lead_merge',
                'new_value' => json_encode([
                    'merged_lead_ids' => $secondaries->keys()->values()->all(),
                    'log_id' => $log->id,
                    'counts' => $counts,
                ]),
            ]);

            return ['primary' => $primary->fresh(), 'log' => $log->fresh(), 'counts' => $counts];
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  list<int>  $leadIds
     * @return int
     */
    public function bulkIgnore(array $leadIds, User $actor, string $reason): int
    {
        $leadIds = array_values(array_unique(array_map('intval', $leadIds)));
        $updated = 0;

        DB::transaction(function () use ($leadIds, $actor, $reason, &$updated) {
            $leads = Lead::query()
                ->whereIn('id', $leadIds)
                ->whereNull('merged_into_lead_id')
                ->whereNull('ignored_at')
                ->where('status', '!=', 'converted')
                ->get();

            foreach ($leads as $lead) {
                $lead->update([
                    'ignored_at' => now(),
                    'ignore_reason' => $reason,
                    'status' => 'lost',
                ]);
                NextAction::query()
                    ->where('subject_type', $lead->getMorphClass())
                    ->where('subject_id', $lead->id)
                    ->whereIn('status', ['pending', 'overdue', 'escalated'])
                    ->update(['status' => 'completed', 'last_action_at' => now()]);

                AuditLog::create([
                    'user_id' => $actor->id,
                    'user_role' => $actor->role,
                    'object_type' => 'lead',
                    'object_id' => $lead->id,
                    'action_type' => 'lead_ignored',
                    'new_value' => json_encode(['reason' => $reason]),
                ]);
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Lead>  $secondaries
     * @param  array<string, mixed>  $fieldChoices
     */
    private function applyFieldChoices(Lead $primary, $secondaries, array $fieldChoices): void
    {
        $allowed = ['contact_name', 'phone', 'email', 'address', 'project_description', 'source'];
        $updates = [];
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $fieldChoices)) {
                continue;
            }
            $choice = $fieldChoices[$field];
            if (is_numeric($choice)) {
                $from = $secondaries->get((int) $choice) ?? ($primary->id === (int) $choice ? $primary : null);
                if ($from) {
                    $updates[$field] = $from->{$field};
                }
            } else {
                $updates[$field] = $choice;
            }
        }
        if ($updates !== []) {
            $primary->update($updates);
        }
    }
}
