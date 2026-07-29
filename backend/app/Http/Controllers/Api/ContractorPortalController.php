<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contractors\ContractorAvailabilityService;
use App\Services\Contractors\ContractorOnboardingService;
use App\Services\Contractors\ContractorProfileCompleteness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorPortalController extends Controller
{
    public function __construct(
        private ContractorAvailabilityService $availability,
        private ContractorOnboardingService $onboarding,
        private ContractorProfileCompleteness $completeness,
    ) {}

    public function availability(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $profile = $this->completeness->ensureProfileForUser($user);

        return response()->json($this->availability->present($profile));
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $data = $request->validate([
            'working_hours' => 'nullable|array',
            'blackout_ranges' => 'nullable|array',
            'blackout_ranges.*.start' => 'required_with:blackout_ranges|date',
            'blackout_ranges.*.end' => 'required_with:blackout_ranges|date',
            'min_notice_hours' => 'nullable|integer|min:0|max:720',
            'daily_capacity' => 'nullable|integer|min:1|max:20',
            'availability_paused' => 'nullable|boolean',
            'availability_paused_until' => 'nullable|date',
            'availability_notes' => 'nullable|string|max:2000',
            'services' => 'nullable|array',
            'cities' => 'nullable|array',
        ]);
        $profile = $this->completeness->ensureProfileForUser($user);
        $updated = $this->availability->update($profile, $data);

        return response()->json([
            'message' => 'Availability saved. Accepted work was not changed.',
            'availability' => $this->availability->present($updated),
        ]);
    }

    public function onboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'contractor') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($this->onboarding->checklist($user));
    }
}
