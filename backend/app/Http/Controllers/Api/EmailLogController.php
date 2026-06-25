<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EmailLog::with(['user:id,name', 'job:id,address']);

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->job_id) {
            $query->where('related_job_id', $request->job_id);
        }

        return response()->json($query->latest()->paginate(30));
    }
}
