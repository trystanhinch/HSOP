<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authorization\ContentEditorAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A-36 — Owner assigns brands to content editors (PM-01/PM-02 pattern).
 */
class ContentEditorBrandAssignmentController extends Controller
{
    public function __construct(private ContentEditorAuthorizationService $authz) {}

    public function show(User $user): JsonResponse
    {
        if ($user->role !== 'content_editor') {
            return response()->json(['message' => 'Not a content editor.'], 422);
        }

        return response()->json([
            'user_id' => $user->id,
            'brand_ids' => $this->authz->assignedBrandIds($user)->values(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'brand_ids' => ['required', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
        ]);

        $result = $this->authz->syncAssignments($user, $data['brand_ids'], $request->user());

        return response()->json([
            'user_id' => $user->id,
            'brand_ids' => $result['after'],
            'before' => $result['before'],
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== 'content_editor') {
            return response()->json(['brand_ids' => []]);
        }

        return response()->json([
            'brand_ids' => $this->authz->assignedBrandIds($user)->values(),
        ]);
    }
}
