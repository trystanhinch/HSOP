<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadMergeLog;
use App\Models\User;
use App\Services\Customers\CustomerValidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadDuplicateService
{
    public function __construct(
        private CustomerValidationService $validation,
    ) {}

    /**
     * Assign / refresh duplicate groups for active (non-merged, non-ignored) leads.
     *
     * @return array{groups: int, leads_flagged: int}
     */
    public function regroup(?int $limit = 500): array
    {
        $leads = Lead::query()
            ->whereNull('merged_into_lead_id')
            ->whereNull('ignored_at')
            ->whereNotIn('status', ['converted', 'lost'])
            ->orderByDesc('id')
            ->limit($limit ?? 500)
            ->get();

        $byKey = [];
        foreach ($leads as $lead) {
            $keys = $this->matchKeys($lead);
            foreach ($keys as $key) {
                $byKey[$key][] = $lead->id;
            }
        }

        $groups = 0;
        $flagged = 0;
        $seenGroups = [];

        foreach ($byKey as $key => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            $groupSig = implode('-', $ids);
            if (isset($seenGroups[$groupSig])) {
                continue;
            }
            $seenGroups[$groupSig] = true;
            $groupId = 'ldg_'.substr(sha1($groupSig), 0, 16);
            $primaryId = $this->recommendPrimaryId($ids);
            foreach ($ids as $id) {
                Lead::where('id', $id)->update([
                    'duplicate_group_id' => $groupId,
                    'is_duplicate_primary' => $id === $primaryId,
                ]);
                $flagged++;
            }
            $groups++;
        }

        return ['groups' => $groups, 'leads_flagged' => $flagged];
    }

    /**
     * @param  list<int>  $leadIds
     */
    public function recommendPrimaryId(array $leadIds): int
    {
        $leads = Lead::query()->whereIn('id', $leadIds)->get();
        $scored = $leads->map(function (Lead $l) {
            $score = 0;
            if ($this->validation->isValidPhone($l->phone)) {
                $score += 3;
            }
            if ($this->validation->isValidEmail($l->email)) {
                $score += 3;
            }
            if ($l->contact_name && strlen(trim($l->contact_name)) > 2) {
                $score += 2;
            }
            if ($l->address) {
                $score += 1;
            }
            if (! $l->needs_manual_review) {
                $score += 2;
            }
            if ($l->assigned_pm_id) {
                $score += 1;
            }
            // Prefer older (first intake) as primary when tied
            $score += max(0, 1000000 - (int) $l->id) / 1000000;

            return ['id' => $l->id, 'score' => $score];
        })->sortByDesc('score')->values();

        return (int) ($scored->first()['id'] ?? $leadIds[0]);
    }

    /**
     * @return array{group_id: string, recommended_primary_id: int, leads: list<Lead>}
     */
    public function group(string $groupId): array
    {
        $leads = Lead::query()
            ->with(['assignedPm:id,name', 'company:id,name', 'brand:id,company_name,slug', 'companySource:id,company_name'])
            ->where('duplicate_group_id', $groupId)
            ->whereNull('merged_into_lead_id')
            ->orderByDesc('is_duplicate_primary')
            ->orderBy('id')
            ->get();

        $ids = $leads->pluck('id')->all();
        $primary = $leads->firstWhere('is_duplicate_primary', true)?->id
            ?? ($ids ? $this->recommendPrimaryId($ids) : 0);

        return [
            'group_id' => $groupId,
            'recommended_primary_id' => (int) $primary,
            'leads' => $leads->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function matchKeys(Lead $lead): array
    {
        $keys = [];
        $phoneKey = $this->validation->phoneMatchKey($lead->phone);
        if ($phoneKey) {
            $keys[] = 'phone:'.$phoneKey;
        }
        if ($lead->email && $this->validation->isValidEmail($lead->email)) {
            $keys[] = 'email:'.strtolower(trim($lead->email));
        }

        return $keys;
    }
}
