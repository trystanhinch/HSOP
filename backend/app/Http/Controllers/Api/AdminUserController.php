<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::whereIn('role', ['pm', 'contractor'])
            ->with('contractor:id,user_id,approval_status,wcb_status,liability_insurance_status')
            ->latest();

        if ($request->role) {
            $query->where('role', $request->role);
        }

        return response()->json(
            $query->get(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:pm,contractor',
            'password' => 'nullable|string|min:8',
        ]);

        $password = $data['password'] ?? Str::random(12);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $password,
            'role' => $data['role'],
            'status' => 'active',
        ]);

        if ($data['role'] === 'contractor') {
            Contractor::create([
                'user_id' => $user->id,
                'legal_name' => $data['name'],
                'operating_name' => $data['name'],
                'contact_name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'approval_status' => 'approved',
                'services' => [],
                'cities' => [],
            ]);
        }

        if ($user->phone) {
            $roleLabel = $data['role'] === 'pm' ? 'Project Manager' : 'Contractor';
            try {
                app(SmsService::class)->send(
                    $user->phone,
                    "Hi {$user->name}, your ServiceOP {$roleLabel} account has been created. ".
                    'Login at: https://serviceop-vbstp.ondigitalocean.app '.
                    "Email: {$user->email} / Password: {$password}",
                    'account_created',
                    $user->id,
                    null
                );
            } catch (\Throwable) {
                // SMS failure should not block account creation
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_created',
            'new_value' => ['role' => $data['role'], 'email' => $data['email']],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Account created successfully',
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role']),
            'password' => $password,
        ], 201);
    }

    public function destroy(User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin'], true)) {
            return response()->json(['message' => 'Cannot delete this account type'], 422);
        }

        $user->update(['status' => 'inactive']);

        return response()->json(['message' => 'Account deactivated']);
    }

    public function resetPassword(User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin'], true)) {
            return response()->json(['message' => 'Cannot reset password for this account type'], 422);
        }

        $newPassword = Str::random(10);
        $user->update(['password' => $newPassword]);

        if ($user->phone) {
            try {
                app(SmsService::class)->send(
                    $user->phone,
                    "Hi {$user->name}, your ServiceOP password has been reset. ".
                    "New password: {$newPassword} — please log in and change it.",
                    'password_reset',
                    $user->id,
                    null
                );
            } catch (\Throwable) {
                // SMS failure should not block password reset
            }
        }

        return response()->json([
            'message' => 'Password reset successfully',
            'password' => $newPassword,
            'sms_sent' => ! empty($user->phone),
        ]);
    }
}
