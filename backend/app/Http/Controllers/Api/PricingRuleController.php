<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PricingOverrideLog;
use App\Models\PricingRule;
use App\Services\Pricing\PricingRangeEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PricingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PricingRule::query()
            ->with(['brand:id,domain,company_name,slug', 'companySource:id,company_name'])
            ->orderBy('brand_id')
            ->orderBy('service_category');

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->brand_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } elseif (! $request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        unset($data['override_reason']);
        $this->ensureSingleActive($data);

        $rule = PricingRule::create($data);

        return response()->json($rule->load(['brand:id,domain,company_name', 'companySource:id,company_name']), 201);
    }

    public function show(PricingRule $pricingRule): JsonResponse
    {
        return response()->json($pricingRule->load(['brand:id,domain,company_name', 'companySource:id,company_name']));
    }

    public function update(Request $request, PricingRule $pricingRule): JsonResponse
    {
        $data = $this->validated($request, true);
        $overrideReason = $data['override_reason'] ?? null;
        unset($data['override_reason']);

        $merged = array_merge($pricingRule->only([
            'brand_id', 'service_category', 'status',
        ]), $data);
        $this->ensureSingleActive($merged, $pricingRule->id);

        $before = $pricingRule->only([
            'brand_id', 'service_category', 'rule_type', 'base_rate', 'size_tiers',
            'complexity_modifiers', 'min_price', 'max_price', 'currency', 'status',
            'is_placeholder', 'notes',
        ]);

        $pricingRule->update($data);

        PricingOverrideLog::create([
            'actor_id' => auth()->id(),
            'subject_type' => 'pricing_rule',
            'subject_id' => $pricingRule->id,
            'brand_id' => $pricingRule->brand_id,
            'lead_id' => null,
            'job_id' => null,
            'override_kind' => 'rule_edit',
            'before_json' => $before,
            'after_json' => $pricingRule->fresh()->only(array_keys($before)),
            'reason' => $overrideReason,
        ]);

        return response()->json($pricingRule->fresh()->load(['brand:id,domain,company_name', 'companySource:id,company_name']));
    }

    public function destroy(PricingRule $pricingRule): JsonResponse
    {
        $pricingRule->update(['status' => 'archived']);

        return response()->json(['message' => 'Pricing rule archived.']);
    }

    /**
     * Preview estimator output for a rule / brand (admin validation).
     */
    public function preview(Request $request, PricingRangeEstimator $estimator): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'service_category' => 'required|string|max:80',
            'size_sqft' => 'nullable|numeric|min:0',
            'complexity' => 'nullable|in:simple,standard,complex,unknown',
            'urgency' => 'nullable|in:normal,high',
            'project_description' => 'nullable|string|max:2000',
            'address' => 'nullable|string|max:255',
        ]);

        $brand = Brand::findOrFail($data['brand_id']);

        return response()->json([
            'estimate' => $estimator->estimate($brand, $data),
        ]);
    }

    public function brands(): JsonResponse
    {
        return response()->json(
            Brand::query()->orderBy('company_name')->get(['id', 'domain', 'company_name', 'slug', 'service_categories', 'status'])
        );
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|' : '';

        return $request->validate([
            'brand_id' => $req.'required|exists:brands,id',
            'company_source_id' => 'nullable|exists:company_sources,id',
            'service_category' => $req.'required|string|max:80',
            'rule_type' => [$req.'required', Rule::in(['per_sqft', 'flat', 'tiered'])],
            'base_rate' => 'nullable|numeric|min:0',
            'size_tiers' => 'nullable|array',
            'complexity_modifiers' => 'nullable|array',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'is_placeholder' => 'nullable|boolean',
            'notes' => 'nullable|string|max:5000',
            'override_reason' => 'nullable|string|max:5000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureSingleActive(array $data, ?int $ignoreId = null): void
    {
        if (($data['status'] ?? 'active') !== 'active') {
            return;
        }

        $q = PricingRule::query()
            ->where('brand_id', $data['brand_id'])
            ->where('service_category', $data['service_category'])
            ->where('status', 'active');
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        $q->update(['status' => 'draft']);
    }
}
