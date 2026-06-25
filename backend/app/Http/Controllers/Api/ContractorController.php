<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            Contractor::with('user:id,name')->latest()->paginate(20)
        );
    }

    public function me(Request $request): JsonResponse
    {
        $contractor = Contractor::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($contractor);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['owner', 'pm', 'contractor'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $contractor = Contractor::with('user:id,name')->findOrFail($id);

        if ($user->role === 'contractor' && $contractor->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($contractor);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json([]);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json([]);
    }
}
