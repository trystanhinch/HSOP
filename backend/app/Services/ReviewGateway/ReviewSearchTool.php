<?php

namespace App\Services\ReviewGateway;

use App\Models\Job;
use App\Models\Lead;
use Illuminate\Http\Request;

/**
 * GET search — review-only cross-entity search (does NOT call JobController::search).
 */
class ReviewSearchTool
{
    public const TOOL = 'search';

    public function __construct(private SensitiveDataGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request): array
    {
        $serviceType = $request->query('service_type', $request->query('service_category'));
        $region = $request->query('region');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $leadsQuery = Lead::query()->select([
            'id', 'status', 'service_category', 'address', 'contact_name',
            'source', 'created_at', 'updated_at',
        ]);
        $jobsQuery = Job::query()->select([
            'id', 'lead_id', 'status', 'service_category', 'address', 'job_title',
            'scheduled_start_date', 'created_at', 'updated_at',
        ]);

        if (is_string($serviceType) && $serviceType !== '') {
            $leadsQuery->where('service_category', $serviceType);
            $jobsQuery->where('service_category', $serviceType);
        }
        if (is_string($region) && $region !== '') {
            $leadsQuery->where('address', 'like', '%'.$region.'%');
            $jobsQuery->where('address', 'like', '%'.$region.'%');
        }
        if (is_string($dateFrom) && $dateFrom !== '') {
            $leadsQuery->whereDate('created_at', '>=', $dateFrom);
            $jobsQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if (is_string($dateTo) && $dateTo !== '') {
            $leadsQuery->whereDate('created_at', '<=', $dateTo);
            $jobsQuery->whereDate('created_at', '<=', $dateTo);
        }

        $leads = $leadsQuery->latest('id')->limit(200)->get()->map(fn (Lead $l) => [
            'entity' => 'lead',
            'id' => $l->id,
            'status' => $l->status,
            'service_category' => $l->service_category,
            'address' => $l->address,
            'contact_name' => $l->contact_name,
            'source' => $l->source,
            'created_at' => optional($l->created_at)?->toIso8601String(),
            'updated_at' => optional($l->updated_at)?->toIso8601String(),
        ]);

        $jobs = $jobsQuery->latest('id')->limit(200)->get()->map(fn (Job $j) => [
            'entity' => 'job',
            'id' => $j->id,
            'lead_id' => $j->lead_id,
            'status' => $j->status,
            'service_category' => $j->service_category,
            'address' => $j->address,
            'job_title' => $j->job_title,
            'scheduled_start_date' => $j->scheduled_start_date,
            'created_at' => optional($j->created_at)?->toIso8601String(),
            'updated_at' => optional($j->updated_at)?->toIso8601String(),
        ]);

        $merged = $leads->concat($jobs)->sortByDesc('created_at')->values();
        $total = $merged->count();
        $slice = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.search', '1.0.0'),
            'filters' => [
                'service_type' => $serviceType,
                'region' => $region,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'data' => $slice->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];

        return $this->guard->scrub($payload);
    }
}
