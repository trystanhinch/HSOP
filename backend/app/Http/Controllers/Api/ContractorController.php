<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\User;
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

        $contractor = Contractor::with([
            'documents',
            'user:id,name,email,phone,status,created_at',
        ])->findOrFail($id);

        if ($user->role === 'contractor' && $contractor->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($contractor);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
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
            'admin_notes' => 'nullable|string',
        ]);

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

        $contractor->update([
            'legal_name' => $request->legal_name ?? $contractor->legal_name,
            'operating_name' => $request->operating_name ?? $contractor->operating_name,
            'contact_name' => $request->contact_name ?? $contractor->contact_name,
            'phone' => $request->phone ?? $contractor->phone,
            'email' => $request->email ?? $contractor->email,
            'services' => $request->services !== null ? $request->services : $contractor->services,
            'cities' => $request->cities !== null ? $request->cities : $contractor->cities,
            'approval_status' => $request->approval_status ?? $contractor->approval_status,
            'admin_notes' => $request->admin_notes ?? $contractor->admin_notes,
        ]);

        return response()->json([
            'message' => 'Contractor profile updated successfully',
            'contractor' => $contractor->fresh(),
            'user' => $user->fresh()->only(['id', 'name', 'email', 'phone', 'status']),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json([]);
    }
}
