<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\User;
use App\Services\Authorization\PmAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PmBrandAssignmentController extends Controller
{
    public function __construct(private readonly PmAuthorizationService $authz) {}

    public function index(Request $request): JsonResponse
    {
        $pms = User::query()
            ->where('role', 'pm')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'status']);

        $rows = $pms->map(function (User $pm) {
            $brandIds = $this->authz->assignedBrandIds($pm);

            return [
                'user_id' => $pm->id,
                'name' => $pm->name,
                'email' => $pm->email,
                'status' => $pm->status,
                'brand_ids' => $brandIds->all(),
                'brands' => Brand::query()->whereIn('id', $brandIds)->get(['id', 'slug', 'company_name']),
            ];
        });

        return response()->json([
            'assignments' => $rows,
            'brands' => Brand::query()->where('status', 'active')->orderBy('company_name')->get(['id', 'slug', 'company_name']),
        ]);
    }

    public function show(User $user): JsonResponse
    {
        if ($user->role !== 'pm') {
            return response()->json(['message' => 'Not a PM user'], 422);
        }
        $brandIds = $this->authz->assignedBrandIds($user);

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
            'brand_ids' => $brandIds->all(),
            'brands' => Brand::query()->whereIn('id', $brandIds)->get(['id', 'slug', 'company_name']),
        ]);
    }

    public function sync(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'brand_ids' => 'present|array',
            'brand_ids.*' => 'integer|exists:brands,id',
        ]);

        $result = $this->authz->syncAssignments($user, $data['brand_ids'], $request->user());

        return response()->json([
            'message' => 'PM brand assignments updated.',
            'assignment' => $result,
        ]);
    }

    /** Current PM's own assigned brands (for Availability UI). */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === 'owner') {
            return response()->json([
                'brand_ids' => Brand::query()->where('status', 'active')->pluck('id'),
                'brands' => Brand::query()->where('status', 'active')->orderBy('company_name')->get(['id', 'slug', 'company_name', 'domain', 'service_categories']),
            ]);
        }
        if ($user->role !== 'pm') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $brandIds = $this->authz->assignedBrandIds($user);

        return response()->json([
            'brand_ids' => $brandIds,
            'brands' => Brand::query()
                ->whereIn('id', $brandIds)
                ->where('status', 'active')
                ->orderBy('company_name')
                ->get(['id', 'slug', 'company_name', 'domain', 'service_categories']),
        ]);
    }
}
