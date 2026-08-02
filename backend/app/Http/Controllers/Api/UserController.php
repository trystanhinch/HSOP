<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function pms(): JsonResponse
    {
        return response()->json(
            User::where('role', 'pm')->where('status', 'active')->get(['id', 'name', 'email'])
        );
    }

    public function contractors(): JsonResponse
    {
        // Assignment picker: only approved + compliant profiles (users.id for ACL payload)
        $profiles = \App\Models\Contractor::productionOnly()
            ->assignable()
            ->with('user:id,name,email,status')
            ->whereHas('user', fn ($q) => $q->where('role', 'contractor')->where('status', 'active'))
            ->get();

        return response()->json(
            $profiles->map(fn ($p) => [
                'id' => $p->user_id, // assignment APIs still expect users.id
                'name' => $p->display_name,
                'email' => $p->email ?: $p->user?->email,
                'contractor_profile_id' => $p->id,
                'state' => $p->state,
                'contractor' => [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'legal_name' => $p->legal_name,
                    'operating_name' => $p->operating_name,
                    'approval_status' => $p->approval_status,
                    'state' => $p->state,
                ],
            ])->values()
        );
    }

    public function index(): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            User::whereNotIn('role', ['ai_super_admin', 'external_review_ai', 'learning_ai'])
                ->orderBy('role')->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'status', 'created_at'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:pm,contractor',
            'password' => 'nullable|string|min:8',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password'] ?? 'password'),
            'role' => $data['role'],
            'status' => 'active',
        ]);

        if ($data['role'] === 'contractor') {
            Contractor::create([
                'user_id' => $user->id,
                'legal_name' => $data['name'],
                'operating_name' => $data['name'],
                'email' => $data['email'],
                'wcb_status' => 'not_uploaded',
                'liability_insurance_status' => 'not_uploaded',
                'approval_status' => 'pending',
                'state' => 'profile_incomplete',
                'services' => [],
                'cities' => [],
            ]);
        }

        return response()->json($user, 201);
    }

    public function toggleSms(User $user): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user->update(['sms_enabled' => ! $user->sms_enabled]);

        return response()->json(['sms_enabled' => $user->sms_enabled]);
    }

    /**
     * Owner-only: grant/revoke can_finalize_learning_eligibility (delegation hook for named PMs).
     */
    public function setCanFinalizeLearning(Request $request, User $user): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        if ($user->role === 'owner') {
            return response()->json([
                'message' => 'Owners already have finalization authority via role; flag is for named non-owner delegates.',
                'can_finalize_learning_eligibility' => (bool) $user->can_finalize_learning_eligibility,
                'can_finalize_learning' => $user->canFinalizeLearningEligibility(),
            ]);
        }

        $user->update([
            'can_finalize_learning_eligibility' => (bool) $data['enabled'],
        ]);

        return response()->json([
            'id' => $user->id,
            'can_finalize_learning_eligibility' => (bool) $user->can_finalize_learning_eligibility,
            'can_finalize_learning' => $user->fresh()->canFinalizeLearningEligibility(),
        ]);
    }
}
