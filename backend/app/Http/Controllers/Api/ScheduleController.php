<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarConflictService;
use App\Services\Calendar\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function __construct(
        private CalendarService $calendar,
        private CalendarConflictService $conflicts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $month = $request->month ?? now()->format('Y-m');
        $view = $request->get('view', 'month');
        if (! in_array($view, ['day', 'week', 'month', 'agenda', 'list'], true)) {
            $view = 'month';
        }

        $payload = $this->calendar->forUser($request->user(), $month, $view);

        return response()->json($payload);
    }

    /**
     * A-31 — Pre-check contractor double-book / active holds before assign/reschedule.
     */
    public function checkConflict(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contractor_id' => 'required|integer|exists:users,id',
            'date' => 'required|date',
            'time' => 'nullable|date_format:H:i',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'travel_buffer_minutes' => 'nullable|integer|min:0|max:240',
            'exclude_type' => 'nullable|string|in:site_visit,job',
            'exclude_id' => 'nullable|integer',
        ]);

        $result = $this->conflicts->checkContractorSlot(
            (int) $data['contractor_id'],
            $data['date'],
            $data['time'] ?? null,
            $data['exclude_type'] ?? null,
            isset($data['exclude_id']) ? (int) $data['exclude_id'] : null,
            (int) ($data['duration_minutes'] ?? 60),
            (int) ($data['travel_buffer_minutes'] ?? 0),
        );

        return response()->json($result, $result['conflict'] ? 409 : 200);
    }
}
