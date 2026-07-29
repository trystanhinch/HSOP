<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDeveloper;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A-23 — Password re-entry unlock for developer diagnostics (15-minute TTL).
 */
class DeveloperUnlockController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'is_developer' => $user?->isDeveloper() ?? false,
            'unlocked' => $user ? EnsureDeveloper::isUnlocked($user->id) : false,
            'ttl_minutes' => EnsureDeveloper::UNLOCK_TTL_MINUTES,
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isDeveloper()) {
            return response()->json([
                'message' => 'Developer permission required.',
                'code' => 'developer_required',
            ], 403);
        }

        $data = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Incorrect password.',
            ]);
        }

        EnsureDeveloper::unlock($user->id);

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'developer_diagnostics',
            'object_id' => $user->id,
            'action_type' => 'developer_unlock',
            'new_value' => [
                'unlocked_at' => now()->toIso8601String(),
                'ttl_minutes' => EnsureDeveloper::UNLOCK_TTL_MINUTES,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Developer diagnostics unlocked.',
            'unlocked' => true,
            'ttl_minutes' => EnsureDeveloper::UNLOCK_TTL_MINUTES,
        ]);
    }
}
