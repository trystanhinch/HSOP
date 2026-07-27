<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\Job;
use App\Models\User;
use App\Services\Contractors\ContractorDirectoryService;
use App\Services\Contractors\ContractorProfileCompleteness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorController extends Controller
{
    public function __construct(
        private readonly ContractorDirectoryService $directory,
        private readonly ContractorProfileCompleteness $completeness,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $role = $request->user()->role;
        $query = $this->directory->directoryQuery()
            ->with([
                'user:id,name,email,phone,status,stripe_account_id,stripe_onboarding_status,stripe_payout_ready',
            ])
            ->latest();

        // PM-06: filter to contractors with an assignment tied to this PM's jobs
        if ($role === 'pm') {
            $pmId = $request->user()->id;
            $userIds = Job::productionOnly()
                ->where('pm_id', $pmId)
                ->whereNotNull('contractor_id')
                ->pluck('contractor_id')
                ->unique()
                ->filter()
                ->values();

            $query->whereIn('user_id', $userIds);
        }

        $paginator = $query->paginate(50);
        $items = collect($paginator->items())->map(
            fn (Contractor $c) => $this->directory->serialize($c, $role)
        );

        return response()->json([
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'directory_definition' => 'production contractors where state != deactivated',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $contractor = Contractor::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($this->directory->serialize($contractor, 'contractor'));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['owner', 'pm', 'contractor'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $contractor = Contractor::with([
            'documents',
            'user:id,name,email,phone,status,created_at,stripe_account_id,stripe_onboarding_status,stripe_payout_ready',
        ])->findOrFail($id);

        if ($user->role === 'contractor' && $contractor->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->role === 'pm') {
            $linked = Job::productionOnly()
                ->where('pm_id', $user->id)
                ->where(function ($q) use ($contractor) {
                    $q->where('contractor_profile_id', $contractor->id)
                        ->orWhere('contractor_id', $contractor->user_id);
                })
                ->exists();
            if (! $linked) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
        }

        $payload = $this->directory->serialize($contractor, $user->role === 'pm' ? 'pm' : ($user->role === 'owner' ? 'owner' : 'contractor'));
        $payload['documents'] = $contractor->documents;
        $payload['user'] = $contractor->user?->only(['id', 'name', 'email', 'phone', 'status', 'created_at']);

        // Owner-only Stripe details already gated in serialize; strip payment_info for PM
        if ($user->role === 'pm') {
            unset($payload['stripe'], $payload['admin_notes']);
        }

        return response()->json($payload);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $contractor = Contractor::findOrFail($id);
        $user = User::findOrFail($contractor->user_id);

        $request->validate([
            'name' => 'nullable|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'operating_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:users,email,'.$user->id,
            'services' => 'nullable|array',
            'cities' => 'nullable|array',
            'approval_status' => 'nullable|in:pending,approved,suspended',
            'state' => 'nullable|in:'.implode(',', Contractor::STATES),
            'admin_notes' => 'nullable|string',
        ]);

        // Only owner may set suspended/deactivated/approved overrides
        if ($request->filled('state') && $request->user()->role !== 'owner') {
            return response()->json(['message' => 'Only owner can change contractor state'], 403);
        }

        $userUpdate = [];
        if ($request->filled('name')) {
            $userUpdate['name'] = $request->name;
        }
        if ($request->filled('email')) {
            $userUpdate['email'] = $request->email;
        }
        if ($request->filled('phone')) {
            $userUpdate['phone'] = $request->phone;
        }
        if (! empty($userUpdate)) {
            $user->update($userUpdate);
        }

        $data = [
            'legal_name' => $request->legal_name ?? $contractor->legal_name,
            'operating_name' => $request->operating_name ?? $contractor->operating_name,
            'contact_name' => $request->contact_name ?? $contractor->contact_name,
            'phone' => $request->phone ?? $contractor->phone,
            'email' => $request->email ?? $contractor->email,
            'services' => $request->services !== null ? $request->services : $contractor->services,
            'cities' => $request->cities !== null ? $request->cities : $contractor->cities,
            'admin_notes' => $request->admin_notes ?? $contractor->admin_notes,
        ];

        if ($request->filled('state')) {
            $data['state'] = $request->state;
            $data['approval_status'] = $this->completeness->syncApprovalStatus($request->state);
        } elseif ($request->filled('approval_status')) {
            $data['approval_status'] = $request->approval_status;
            if ($request->approval_status === 'suspended') {
                $data['state'] = 'suspended';
            }
        }

        $contractor->update($data);

        return response()->json([
            'message' => 'Contractor profile updated successfully',
            'contractor' => $this->directory->serialize($contractor->fresh(['user']), $request->user()->role),
            'user' => $user->fresh()->only(['id', 'name', 'email', 'phone', 'status']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json([]);
    }
}
