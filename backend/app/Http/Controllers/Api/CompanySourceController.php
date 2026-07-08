<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CompanySource::with('defaultPm:id,name,email')
            ->orderBy('company_name');

        if ($request->status) {
            $query->where('status', $request->status);
        } elseif (! $request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $source = CompanySource::create($data);

        return response()->json($source->load('defaultPm:id,name,email'), 201);
    }

    public function show(CompanySource $companySource): JsonResponse
    {
        return response()->json($companySource->load('defaultPm:id,name,email'));
    }

    public function update(Request $request, CompanySource $companySource): JsonResponse
    {
        $companySource->update($this->validated($request, true));

        return response()->json($companySource->fresh()->load('defaultPm:id,name,email'));
    }

    public function destroy(CompanySource $companySource): JsonResponse
    {
        $companySource->update(['status' => 'archived']);

        return response()->json(['message' => 'Company source archived.']);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'company_name' => ($partial ? 'sometimes|' : '').'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'service_categories' => 'nullable|array',
            'service_categories.*' => 'string|max:100',
            'google_review_url' => 'nullable|url|max:500',
            'default_pm_id' => 'nullable|exists:users,id',
            'sender_identity' => 'nullable|string|max:255',
            'lead_parsing_rule' => 'nullable|string',
            'marketing_cost_monthly' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,paused,testing,archived',
        ];

        return $request->validate($rules);
    }
}
