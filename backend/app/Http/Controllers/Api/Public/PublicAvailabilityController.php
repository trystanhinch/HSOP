<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BookingHold;
use App\Services\Booking\BookingService;
use App\Services\PublicIntake\PublicIntakeSessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicAvailabilityController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private PublicIntakeSessionService $sessions,
    ) {}

    /**
     * GET /api/public/availability?service=drywall_paint&days=14
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        $data = $request->validate([
            'service' => 'nullable|string|max:80',
            'days' => 'nullable|integer|min:1|max:60',
            'from' => 'nullable|date',
        ]);

        $service = $data['service'] ?? null;
        if ($service) {
            $allowed = array_column($brand->serviceCatalog(), 'key');
            if ($allowed !== [] && ! in_array($service, $allowed, true)) {
                return response()->json(['message' => 'Service not offered by this brand.'], 422);
            }
        }

        $slots = $this->bookings->availableSlots(
            $brand,
            $service,
            isset($data['from']) ? Carbon::parse($data['from']) : null,
            $data['days'] ?? null,
        );

        return response()->json([
            'brand_id' => $brand->id,
            'brand_domain' => $brand->domain,
            'service' => $service,
            'timezone' => config('booking.default_timezone', 'America/Vancouver'),
            'hold_ttl_seconds' => (int) config('booking.hold_ttl_seconds', 600),
            'slots' => $slots,
            'count' => count($slots),
        ]);
    }

    /**
     * Soft-hold a slot during intake.
     */
    public function hold(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');

        $data = $request->validate([
            'session_token' => 'nullable|string|max:64',
            'slot_start' => 'required|date',
            'slot_end' => 'required|date|after:slot_start',
            'resource_key' => 'required|string|max:64',
            'service' => 'nullable|string|max:80',
            'pm_id' => 'nullable|integer',
            'contractor_id' => 'nullable|integer',
        ]);

        $token = $data['session_token']
            ?? $request->cookie(config('public.intake_cookie', 'serviceop_intake_token'))
            ?? $request->header('X-Intake-Token');

        if (! $token) {
            return response()->json(['message' => 'Missing intake session token'], 401);
        }

        $session = $this->sessions->findValidByToken($token, $brand);
        if (! $session) {
            return response()->json(['message' => 'Intake session expired or not found'], 404);
        }

        try {
            $hold = $this->bookings->holdSlot(
                $brand,
                $session,
                Carbon::parse($data['slot_start'])->utc(),
                Carbon::parse($data['slot_end'])->utc(),
                $data['resource_key'],
                $data['service'] ?? ($session->conversation_state['collected']['service_category'] ?? null),
                $data['pm_id'] ?? null,
                $data['contractor_id'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'slot_unavailable',
            ], 409);
        }

        return response()->json([
            'hold_token' => $hold->hold_token,
            'status' => $hold->status,
            'held_until' => $hold->held_until?->toIso8601String(),
            'slot_start' => $hold->slot_start?->toIso8601String(),
            'slot_end' => $hold->slot_end?->toIso8601String(),
            'resource_key' => $hold->resource_key,
        ], 201);
    }

    public function releaseHold(Request $request): JsonResponse
    {
        /** @var Brand $brand */
        $brand = $request->attributes->get('brand');
        $data = $request->validate([
            'hold_token' => 'required|string|max:64',
        ]);

        $hold = BookingHold::query()
            ->where('hold_token', $data['hold_token'])
            ->where('brand_id', $brand->id)
            ->first();

        if (! $hold) {
            return response()->json(['message' => 'Hold not found'], 404);
        }

        if ($hold->status === 'held') {
            $this->bookings->cancelHold($hold);
        }

        return response()->json(['message' => 'Hold released', 'status' => 'cancelled']);
    }
}
