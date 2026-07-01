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
            'title' => $j->job_title ?? $j->customer?->name,
            'date' => $j->scheduled_start_date?->format('Y-m-d'),
            'time' => $j->scheduled_start_time,
            'status' => $j->status,
            'address' => $j->address,
            'customer_name' => $j->customer?->name ?? '',
            'url' => '/jobs/'.$j->id,
        ]);

        $siteVisitsQuery = SiteVisit::with(['lead', 'pm:id,name', 'contractor:id,name', 'customer:id,name'])
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
            'time' => $sv->visit_time,
            'status' => $sv->status,
            'address' => $sv->lead->address ?? '',
            'customer_name' => $sv->lead->contact_name ?? '',
            'url' => '/leads/'.$sv->lead_id,
        ]);

        return response()->json([
            'month' => $month,
            'jobs' => $jobs,
            'site_visits' => $siteVisits,
            'all' => $jobs->concat($siteVisits)->sortBy('date')->values(),
        ]);
    }
}
