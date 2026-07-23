<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Brand;
use App\Models\CompanySource;
use App\Models\Contractor;
use App\Models\Lead;
use App\Models\NextAction;
use App\Models\User;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Support\Collection;

/**
 * Thin rule-based contractor match for Phase 4 booking confirmation.
 *
 * Selection rule: least-recently-assigned among eligible contractors in the
 * brand CompanySource pool who offer the lead's service_category.
 * Why: fair, explainable load balancing without a scoring engine.
 */
class ContractorBookingMatcher
{
    public const RULE = 'least_recently_assigned';

    /**
     * @return array{
     *   matched: bool,
     *   contractor_user_id: int|null,
     *   rule: string|null,
     *   reason: string,
     *   eligible_count: int,
     *   next_action_id: int|null,
     *   meta: array<string, mixed>
     * }
     */
    public function matchForLead(Lead $lead, ?Brand $brand = null): array
    {
        $brand = $brand ?: ($lead->brand_id ? Brand::find($lead->brand_id) : null);
        $category = trim((string) ($lead->service_category ?? ''));
        $source = $this->resolveCompanySource($lead, $brand);

        if (! $source) {
            $na = $this->createManualAssignNextAction(
                $lead,
                'Assign a contractor — no CompanySource linked to this brand/lead for auto-matching.'
            );

            return $this->miss('No CompanySource for brand/lead.', $na);
        }

        $poolIds = collect($source->default_contractor_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($poolIds->isEmpty()) {
            $na = $this->createManualAssignNextAction(
                $lead,
                'Assign a contractor — no contractor pool configured on CompanySource "'.$source->company_name.'".'
            );

            return $this->miss('CompanySource contractor pool is empty.', $na, [
                'company_source_id' => $source->id,
            ]);
        }

        $eligible = $this->eligibleContractors($poolIds, $category, $brand);

        if ($eligible->isEmpty()) {
            $na = $this->createManualAssignNextAction(
                $lead,
                $category !== ''
                    ? 'Assign a contractor — none in the brand pool cover service "'.$category.'".'
                    : 'Assign a contractor — lead has no service_category and pool could not be filtered.'
            );

            return $this->miss('No eligible contractors for category.', $na, [
                'company_source_id' => $source->id,
                'service_category' => $category ?: null,
                'pool_size' => $poolIds->count(),
            ]);
        }

        $chosen = $this->pickLeastRecentlyAssigned($eligible, $brand?->id, $source->id);

        return [
            'matched' => true,
            'contractor_user_id' => (int) $chosen['user_id'],
            'rule' => self::RULE,
            'reason' => 'Selected '.$chosen['name'].' via least-recently-assigned among '
                .$eligible->count().' eligible contractor(s) for '
                .($category !== '' ? $category : 'any service')
                .' on CompanySource #'.$source->id.'.',
            'eligible_count' => $eligible->count(),
            'next_action_id' => null,
            'meta' => [
                'company_source_id' => $source->id,
                'service_category' => $category ?: null,
                'eligible_user_ids' => $eligible->pluck('user_id')->values()->all(),
                'last_assigned_at' => $chosen['last_assigned_at'],
                'contractor_profile_id' => $chosen['contractor_id'],
            ],
        ];
    }

    /**
     * @param  Collection<int, int>  $poolUserIds
     * @return Collection<int, array{user_id: int, contractor_id: int, name: string, last_assigned_at: ?string}>
     */
    private function eligibleContractors(Collection $poolUserIds, string $category, ?Brand $brand): Collection
    {
        $users = User::query()
            ->whereIn('id', $poolUserIds->all())
            ->where('role', 'contractor')
            ->where('status', 'active')
            ->with(['contractor'])
            ->get();

        $catalog = $brand?->serviceCatalog() ?? [];

        return $users
            ->filter(function (User $user) use ($category, $catalog) {
                $profile = $user->contractor;
                if (! $profile || $profile->approval_status !== 'approved') {
                    return false;
                }
                if ($category === '') {
                    // No category on lead — any approved pool member is eligible
                    return true;
                }

                return $this->servicesCoverCategory($profile->services ?? [], $category, $catalog);
            })
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'contractor_id' => $user->contractor->id,
                'name' => $user->name,
                'last_assigned_at' => null,
            ])
            ->values();
    }

    /**
     * @param  list<mixed>  $services
     * @param  list<array{key: string, label: string, keywords?: list<string>}>  $catalog
     */
    private function servicesCoverCategory(array $services, string $category, array $catalog): bool
    {
        $needles = $this->categoryNeedles($category, $catalog);
        foreach ($services as $svc) {
            $hay = strtolower(trim((string) $svc));
            if ($hay === '') {
                continue;
            }
            $hayNorm = preg_replace('/[^a-z0-9]+/', '_', $hay) ?? $hay;
            foreach ($needles as $needle) {
                if ($hay === $needle || $hayNorm === $needle || str_contains($hay, $needle) || str_contains($hayNorm, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{key: string, label: string, keywords?: list<string>}>  $catalog
     * @return list<string>
     */
    private function categoryNeedles(string $category, array $catalog): array
    {
        $needles = [strtolower($category)];
        $needles[] = strtolower(preg_replace('/[^a-z0-9]+/', '_', $category) ?? $category);

        foreach ($catalog as $item) {
            if (($item['key'] ?? '') === $category) {
                $needles[] = strtolower($item['label'] ?? '');
                foreach ($item['keywords'] ?? [] as $kw) {
                    $needles[] = strtolower((string) $kw);
                }
            }
        }

        // Common aliases for Acutera keys
        if ($category === 'drywall_paint') {
            array_push($needles, 'drywall', 'paint', 'painting', 'mudding', 'taping');
        }
        if ($category === 'insulation') {
            array_push($needles, 'insulation', 'attic');
        }
        if ($category === 'roofing') {
            array_push($needles, 'roof', 'roofing', 'shingle');
        }

        return array_values(array_unique(array_filter($needles)));
    }

    /**
     * @param  Collection<int, array{user_id: int, contractor_id: int, name: string, last_assigned_at: ?string}>  $eligible
     * @return array{user_id: int, contractor_id: int, name: string, last_assigned_at: ?string}
     */
    private function pickLeastRecentlyAssigned(Collection $eligible, ?int $brandId, int $companySourceId): array
    {
        $ids = $eligible->pluck('user_id')->all();

        $lastByUser = Booking::query()
            ->whereIn('contractor_id', $ids)
            ->where('status', 'confirmed')
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->selectRaw('contractor_id, MAX(confirmed_at) as last_at')
            ->groupBy('contractor_id')
            ->pluck('last_at', 'contractor_id');

        // Also consider site-visit assignments on leads for this brand/source
        $leadLast = Lead::query()
            ->whereIn('site_visit_contractor_id', $ids)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when(! $brandId, fn ($q) => $q->where('company_source_id', $companySourceId))
            ->whereNotNull('site_visit_date')
            ->selectRaw('site_visit_contractor_id, MAX(updated_at) as last_at')
            ->groupBy('site_visit_contractor_id')
            ->pluck('last_at', 'site_visit_contractor_id');

        $scored = $eligible->map(function (array $row) use ($lastByUser, $leadLast) {
            $a = $lastByUser[$row['user_id']] ?? null;
            $b = $leadLast[$row['user_id']] ?? null;
            $timestamps = array_filter([$a, $b]);
            $latest = $timestamps === [] ? null : max($timestamps);
            $row['last_assigned_at'] = $latest ? (string) $latest : null;
            $row['sort_key'] = $latest ? strtotime((string) $latest) : 0;

            return $row;
        })->sortBy([
            ['sort_key', 'asc'],
            ['user_id', 'asc'],
        ])->values();

        $pick = $scored->first();
        unset($pick['sort_key']);

        return $pick;
    }

    private function resolveCompanySource(Lead $lead, ?Brand $brand): ?CompanySource
    {
        if ($lead->company_source_id) {
            return CompanySource::find($lead->company_source_id);
        }
        if ($brand?->company_source_id) {
            return $brand->companySource ?: CompanySource::find($brand->company_source_id);
        }

        return null;
    }

    private function createManualAssignNextAction(Lead $lead, string $description): NextAction
    {
        $dueAt = app(WorkflowSettings::class)->pmContactDueAt();

        return NextAction::create([
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'action_description' => $description,
            'responsible_role' => $lead->assigned_pm_id ? 'pm' : 'owner',
            'responsible_user_id' => $lead->assigned_pm_id,
            'due_at' => $dueAt,
            'status' => 'pending',
            'escalation_rule' => 'assign_contractor_booking',
            'last_action_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *   matched: bool,
     *   contractor_user_id: int|null,
     *   rule: string|null,
     *   reason: string,
     *   eligible_count: int,
     *   next_action_id: int|null,
     *   meta: array<string, mixed>
     * }
     */
    private function miss(string $reason, NextAction $na, array $meta = []): array
    {
        return [
            'matched' => false,
            'contractor_user_id' => null,
            'rule' => null,
            'reason' => $reason,
            'eligible_count' => 0,
            'next_action_id' => $na->id,
            'meta' => $meta,
        ];
    }
}
