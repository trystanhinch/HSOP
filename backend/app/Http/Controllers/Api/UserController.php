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
        return response()->json(
            User::where('role', 'contractor')->where('status', 'active')
                ->with('contractor:id,user_id,legal_name,operating_name,approval_status')
                ->get(['id', 'name', 'email'])
        );
    }

    public function index(): JsonResponse
    {
        if (auth()->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(
            User::orderBy('role')->orderBy('name')->get(['id', 'name', 'email', 'role', 'status', 'created_at'])
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
                'approval_status' => 'pending',
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
}
