<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = $request->get('month', now()->format('Y-m'));

        [$year, $monthNum] = explode('-', $month);

        $query = Job::with(['customer:id,name', 'contractor:id,name', 'pm:id,name'])
            ->whereNotNull('scheduled_start_date')
            ->whereYear('scheduled_start_date', $year)
            ->whereMonth('scheduled_start_date', $monthNum);

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        } elseif ($user->role === 'contractor') {
            $query->where('contractor_id', $user->id);
        }

        $jobs = $query->get();

        return response()->json([
            'month' => $month,
            'jobs' => $jobs,
        ]);
    }
}
