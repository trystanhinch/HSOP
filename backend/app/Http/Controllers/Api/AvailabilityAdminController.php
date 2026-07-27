<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityWindow;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Brand;
use App\Services\Authorization\PmAuthorizationService;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AvailabilityAdminController extends Controller
{
    public function __construct(
        private BookingService $bookings,
        private PmAuthorizationService $authz,
    ) {}

    public function brands(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Brand::query()->where('status', 'active')->orderBy('company_name');

        if ($user->role === 'pm') {
            $ids = $this->authz->assignedBrandIds($user);
            if ($ids->isEmpty()) {
                return response()->json([]);
            }
            $query->whereIn('id', $ids);
        }

        return response()->json(
            $query->get(['id', 'domain', 'company_name', 'slug', 'service_categories'])
        );
    }

    public function windows(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = AvailabilityWindow::query()
            ->with(['brand:id,domain,company_name', 'pm:id,name', 'contractor:id,name'])
            ->orderBy('brand_id')
            ->orderBy('day_of_week');

        if ($request->filled('brand_id')) {
            $brandId = (int) $request->brand_id;
            $this->authz->assertBrandAccess($user, $brandId, 'availability_windows_filter');
            $query->where('brand_id', $brandId);
        } elseif ($user->role === 'pm') {
            $ids = $this->authz->assignedBrandIds($user);
            if ($ids->isEmpty()) {
                return response()->json([]);
            }
            $query->whereIn('brand_id', $ids);
        }

        if ($user->role === 'pm') {
            // 3A: brand-level (pm_id null) OR own windows
            $query->where(function ($q) use ($user) {
                $q->whereNull('pm_id')->orWhere('pm_id', $user->id);
            });
        }

        return response()->json($query->get());
    }

    public function storeWindow(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedWindow($request);
        $this->authz->assertBrandAccess($user, (int) $data['brand_id'], 'availability_create');

        if ($user->role === 'pm') {
            if (! empty($data['pm_id']) && (int) $data['pm_id'] !== (int) $user->id) {
                $this->authz->logDenied($user, 'availability_window', 0, 'create_other_pm', $data);

                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            if (empty($data['pm_id'])) {
                $data['pm_id'] = null;
            }
        }

        $window = AvailabilityWindow::create($data);
        $this->authz->logAvailabilityChange($user, $window, 'availability_window_created');

        return response()->json($window->load(['brand:id,domain,company_name']), 201);
    }

    public function updateWindow(Request $request, AvailabilityWindow $availabilityWindow): JsonResponse
    {
        $user = $request->user();
        $this->authz->assertWindowAccess($user, $availabilityWindow, 'update');

        $before = $availabilityWindow->toArray();
        $data = $this->validatedWindow($request, true);
        if (isset($data['brand_id'])) {
            $this->authz->assertBrandAccess($user, (int) $data['brand_id'], 'availability_update_brand');
        }
        if ($user->role === 'pm' && array_key_exists('pm_id', $data)
            && $data['pm_id'] !== null && (int) $data['pm_id'] !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (($data['status'] ?? null) === 'inactive' && $availabilityWindow->status !== 'inactive') {
            $guard = $this->authz->deactivationGuard($availabilityWindow);
            if ($guard['blocked']) {
                return response()->json([
                    'message' => $guard['message'],
                    'active_bookings' => $guard['bookings'],
                    'active_holds' => $guard['holds'],
                ], 422);
            }
        }

        $availabilityWindow->update($data);
        $this->authz->logAvailabilityChange($user, $availabilityWindow, 'availability_window_updated', $before);

        return response()->json($availabilityWindow->fresh()->load(['brand:id,domain,company_name']));
    }

    public function destroyWindow(Request $request, AvailabilityWindow $availabilityWindow): JsonResponse
    {
        $user = $request->user();
        $this->authz->assertWindowAccess($user, $availabilityWindow, 'deactivate');

        $guard = $this->authz->deactivationGuard($availabilityWindow);
        if ($guard['blocked']) {
            return response()->json([
                'message' => $guard['message'],
                'active_bookings' => $guard['bookings'],
                'active_holds' => $guard['holds'],
            ], 422);
        }

        $before = $availabilityWindow->toArray();
        $availabilityWindow->update(['status' => 'inactive']);
        $this->authz->logAvailabilityChange($user, $availabilityWindow, 'availability_window_deactivated', $before);

        return response()->json(['message' => 'Availability window deactivated.']);
    }

    public function bookings(Request $request): JsonResponse
    {
        $user = $request->user();
        $brandId = $request->filled('brand_id') ? (int) $request->brand_id : null;
        if ($brandId) {
            $this->authz->assertBrandAccess($user, $brandId, 'availability_bookings_filter');
        }

        $this->bookings->releaseExpiredHolds($brandId);

        $bookings = Booking::query()
            ->with(['lead:id,contact_name,email,phone,service_category', 'brand:id,domain,company_name'])
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($user->role === 'pm' && ! $brandId, function ($q) use ($user) {
                $ids = $this->authz->assignedBrandIds($user);
                $q->whereIn('brand_id', $ids->isEmpty() ? [-1] : $ids);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('slot_start')
            ->limit(200)
            ->get();

        $holds = BookingHold::query()
            ->with(['brand:id,domain,company_name'])
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($user->role === 'pm' && ! $brandId, function ($q) use ($user) {
                $ids = $this->authz->assignedBrandIds($user);
                $q->whereIn('brand_id', $ids->isEmpty() ? [-1] : $ids);
            })
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
