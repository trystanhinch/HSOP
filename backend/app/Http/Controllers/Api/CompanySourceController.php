<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanySource;
use App\Models\CompanySourceVersion;
use App\Services\LeadIntake\CompanySourceParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySourceController extends Controller
{
    public function __construct(private CompanySourceParserService $parserService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CompanySource::with('defaultPm:id,name,email')
            ->orderBy('priority')
            ->orderBy('company_name');

        if ($request->status) {
            $query->where('status', $request->status);
        } elseif (! $request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }

        $rows = $query->get()->map(fn (CompanySource $s) => $this->parserService->serializeSource($s));

        return response()->json($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['parser_type'] = $data['parser_type'] ?? CompanySourceParserService::PARSER_TYPE;
        $data['parser_version'] = $data['parser_version'] ?? CompanySourceParserService::PARSER_VERSION;
        $data['priority'] = $data['priority'] ?? 100;
        $data['fallback_behavior'] = $data['fallback_behavior'] ?? 'category_then_quarantine';

        $source = CompanySource::create($data);

        return response()->json($this->parserService->serializeSource($source->load('defaultPm:id,name,email')), 201);
    }

    public function show(CompanySource $companySource): JsonResponse
    {
        return response()->json($this->parserService->serializeSource(
            $companySource->load('defaultPm:id,name,email')
        ));
    }

    public function update(Request $request, CompanySource $companySource): JsonResponse
    {
        $updated = $this->parserService->updateVersioned(
            $companySource,
            $this->validated($request, true),
            $request->user()
        );

        return response()->json($this->parserService->serializeSource(
            $updated->load('defaultPm:id,name,email')
        ));
    }

    public function destroy(Request $request, CompanySource $companySource): JsonResponse
    {
        $this->parserService->updateVersioned(
            $companySource,
            ['status' => 'archived'],
            $request->user()
        );

        return response()->json(['message' => 'Company source archived.']);
    }

    public function testParser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'raw_email' => 'required|string|min:10|max:200000',
        ]);

        return response()->json($this->parserService->testParser($data['raw_email']));
    }

    public function health(CompanySource $companySource): JsonResponse
    {
        return response()->json([
            'company_source_id' => $companySource->id,
            'health' => $this->parserService->healthFor($companySource),
        ]);
    }

    public function versions(CompanySource $companySource): JsonResponse
    {
        $rows = CompanySourceVersion::with('changer:id,name,role')
            ->where('company_source_id', $companySource->id)
            ->orderByDesc('version')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
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
            'default_contractor_ids' => 'nullable|array',
            'default_contractor_ids.*' => 'integer|exists:users,id',
            'sender_identity' => 'nullable|string|max:255',
            'lead_parsing_rule' => 'nullable|string',
            'intake_allow_patterns' => 'nullable|array',
            'intake_allow_patterns.*' => 'string|max:255',
            'marketing_cost_monthly' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,paused,testing,archived',
            'priority' => 'nullable|integer|min:1|max:9999',
            'parser_type' => 'nullable|string|max:60',
            'parser_version' => 'nullable|string|max:40',
            'fallback_behavior' => 'nullable|in:category_then_quarantine,none',
        ];

        return $request->validate($rules);
    }
}
