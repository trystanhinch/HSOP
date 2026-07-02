<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\SiteVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $month = $request->month ?? now()->format('Y-m');
        [$year, $mon] = explode('-', $month);
        $user = auth()->user();

        $siteVisitsQuery = SiteVisit::with([
            'lead:id,contact_name,address,service_category,status',
            'pm:id,name',
            'contractor:id,name',
        ])
            ->whereMonth('visit_date', $mon)
            ->whereYear('visit_date', $year)
            ->where('status', '!=', 'cancelled');

        if ($user->role === 'pm') {
            $siteVisitsQuery->where('pm_id', $user->id);
        }
        if ($user->role === 'contractor') {
            $siteVisitsQuery->where('contractor_id', $user->id);
        }

        $siteVisits = $siteVisitsQuery->get()->map(fn ($sv) => [
            'type' => 'site_visit',
            'id' => $sv->id,
            'lead_id' => $sv->lead_id,
            'title' => 'Site Visit — '.($sv->lead->contact_name ?? 'Customer'),
            'date' => $sv->visit_date?->format('Y-m-d'),
            'time' => is_string($sv->visit_time) ? substr($sv->visit_time, 0, 5) : $sv->visit_time,
            'status' => $sv->status,
            'address' => $sv->lead->address ?? '',
            'customer_name' => $sv->lead->contact_name ?? '',
            'pm_name' => $sv->pm?->name ?? '',
            'contractor_name' => $sv->contractor?->name ?? '',
            'url' => '/leads/'.$sv->lead_id,
            'color' => 'indigo',
        ]);

        $jobsQuery = Job::with(['customer:id,name', 'pm:id,name', 'contractor:id,name'])
            ->whereNotNull('scheduled_start_date')
            ->whereMonth('scheduled_start_date', $mon)
            ->whereYear('scheduled_start_date', $year);

        if ($user->role === 'pm') {
            $jobsQuery->where('pm_id', $user->id);
        }
        if ($user->role === 'contractor') {
            $jobsQuery->where('contractor_id', $user->id);
        }

        $jobs = $jobsQuery->get()->map(fn ($j) => [
            'type' => 'job',
            'id' => $j->id,
            'title' => $j->job_title ?? ($j->customer?->name ?? 'Job'),
            'date' => $j->scheduled_start_date?->format('Y-m-d'),
            'time' => $j->scheduled_start_time,
            'status' => $j->status,
            'address' => $j->address,
            'customer_name' => $j->customer?->name ?? '',
            'url' => '/jobs/'.$j->id,
            'color' => in_array($j->status, ['in_progress', 'progress_updated'], true) ? 'blue' : 'yellow',
        ]);

        return response()->json([
            'month' => $month,
            'site_visits' => $siteVisits,
            'jobs' => $jobs,
            'all' => $siteVisits->concat($jobs)->sortBy('date')->values(),
        ]);
    }
}
