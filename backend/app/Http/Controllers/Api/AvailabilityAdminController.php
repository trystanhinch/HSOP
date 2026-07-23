<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Brand;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvailabilityAdminController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function brands(): JsonResponse
    {
        return response()->json(
            Brand::query()->where('status', 'active')->orderBy('company_name')->get(['id', 'domain', 'company_name', 'slug', 'service_categories'])
        );
    }

    public function windows(Request $request): JsonResponse
    {
        $query = AvailabilityWindow::query()
            ->with(['brand:id,domain,company_name', 'pm:id,name', 'contractor:id,name'])
            ->orderBy('brand_id')
            ->orderBy('day_of_week');

        if ($request->filled('brand_id')) {
            $query->where('brand_id', (int) $request->brand_id);
        }

        return response()->json($query->get());
    }

    public function storeWindow(Request $request): JsonResponse
    {
        $data = $this->validatedWindow($request);
        $window = AvailabilityWindow::create($data);

        return response()->json($window->load(['brand:id,domain,company_name']), 201);
    }

    public function updateWindow(Request $request, AvailabilityWindow $availabilityWindow): JsonResponse
    {
        $data = $this->validatedWindow($request, true);
        $availabilityWindow->update($data);

        return response()->json($availabilityWindow->fresh()->load(['brand:id,domain,company_name']));
    }

    public function destroyWindow(AvailabilityWindow $availabilityWindow): JsonResponse
    {
        $availabilityWindow->update(['status' => 'inactive']);

        return response()->json(['message' => 'Availability window deactivated.']);
    }

    public function bookings(Request $request): JsonResponse
    {
        $this->bookings->releaseExpiredHolds(
            $request->filled('brand_id') ? (int) $request->brand_id : null
        );

        $bookings = Booking::query()
            ->with(['lead:id,contact_name,email,phone,service_category', 'brand:id,domain,company_name'])
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', (int) $request->brand_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('slot_start')
            ->limit(200)
            ->get();

        $holds = BookingHold::query()
            ->with(['brand:id,domain,company_name'])
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', (int) $request->brand_id))
            ->whereIn('status', ['held', 'expired', 'cancelled', 'confirmed'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'bookings' => $bookings,
            'holds' => $holds,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedWindow(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'brand_id' => [$required, 'integer', 'exists:brands,id'],
            'pm_id' => 'nullable|integer|exists:users,id',
            'contractor_id' => 'nullable|integer|exists:users,id',
            'service_category' => 'nullable|string|max:80',
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'specific_date' => 'nullable|date',
            'start_time' => [$required, 'date_format:H:i'],
            'end_time' => [$required, 'date_format:H:i', 'after:start_time'],
            'slot_duration_minutes' => 'nullable|integer|min:15|max:480',
            'timezone' => 'nullable|string|max:64',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        if (! isset($data['slot_duration_minutes'])) {
            $data['slot_duration_minutes'] = 60;
        }
        if (! isset($data['timezone'])) {
            $data['timezone'] = config('booking.default_timezone', 'America/Vancouver');
        }
        if (! isset($data['status'])) {
            $data['status'] = 'active';
        }

        if (empty($data['specific_date']) && ! array_key_exists('day_of_week', $data) && ! $partial) {
            abort(response()->json(['message' => 'Provide day_of_week or specific_date.'], 422));
        }

        return $data;
    }
}
