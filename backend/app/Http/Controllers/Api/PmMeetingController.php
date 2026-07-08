<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PmMeeting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PmMeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $month = $request->month ?? now()->format('Y-m');
        [$year, $mon] = explode('-', $month);

        $query = PmMeeting::with(['pm:id,name', 'creator:id,name'])
            ->whereMonth('meeting_date', $mon)
            ->whereYear('meeting_date', $year);

        if ($user->role === 'pm') {
            $query->where('pm_id', $user->id);
        }

        return response()->json($query->orderBy('meeting_date')->orderBy('meeting_time')->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'meeting_date' => 'required|date',
            'meeting_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
            'pm_id' => 'required|exists:users,id',
        ]);

        User::where('id', $data['pm_id'])->where('role', 'pm')->firstOrFail();

        $meeting = PmMeeting::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($meeting->load(['pm:id,name', 'creator:id,name']), 201);
    }

    public function update(Request $request, PmMeeting $pmMeeting): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'meeting_date' => 'sometimes|date',
            'meeting_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:2000',
            'pm_id' => 'sometimes|exists:users,id',
        ]);

        if (isset($data['pm_id'])) {
            User::where('id', $data['pm_id'])->where('role', 'pm')->firstOrFail();
        }

        $pmMeeting->update($data);

        return response()->json($pmMeeting->fresh(['pm:id,name', 'creator:id,name']));
    }

    public function destroy(Request $request, PmMeeting $pmMeeting): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $pmMeeting->delete();

        return response()->json(['message' => 'Meeting deleted']);
    }
}
