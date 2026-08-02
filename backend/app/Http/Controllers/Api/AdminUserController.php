<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\PmBrandAssignment;
use App\Models\User;
use App\Services\BrandResolver;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A-14 — Users & Roles management with scope, lifecycle, and session kill.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereIn('role', ['pm', 'contractor', 'owner', 'content_editor'])
            ->with([
                'contractor:id,user_id,approval_status,wcb_status,liability_insurance_status,legal_name,operating_name',
                'brand:id,company_name,domain,slug',
            ])
            ->withCount('tokens')
            ->latest();

        if ($request->role) {
            $query->where('role', $request->role);
        }

        // A-05: hide test-flagged users from the default management list unless explicitly requested.
        if (! $request->boolean('include_test_data') && Schema::hasColumn('users', 'is_test_data')) {
            $query->where(function ($q) {
                $q->where('is_test_data', false)->orWhereNull('is_test_data');
            });
        }

        $users = $query->get();

        $pmBrandMap = [];
        if (Schema::hasTable('pm_brand_assignments')) {
            $assignments = PmBrandAssignment::query()
                ->with('brand:id,company_name,domain,slug')
                ->whereIn('user_id', $users->where('role', 'pm')->pluck('id'))
                ->get()
                ->groupBy('user_id');
            foreach ($assignments as $userId => $rows) {
                $pmBrandMap[$userId] = $rows->map(fn ($r) => [
                    'id' => $r->brand_id,
                    'company_name' => $r->brand?->company_name,
                    'domain' => $r->brand?->domain,
                    'slug' => $r->brand?->slug,
                ])->values()->all();
            }
        }

        $payload = $users->map(function (User $user) use ($pmBrandMap) {
            $brands = [];
            if ($user->role === 'pm') {
                $brands = $pmBrandMap[$user->id] ?? [];
            } elseif ($user->role === 'content_editor' && $user->brand) {
                $brands = [[
                    'id' => $user->brand->id,
                    'company_name' => $user->brand->company_name,
                    'domain' => $user->brand->domain,
                    'slug' => $user->brand->slug,
                ]];
            } elseif ($user->role === 'owner') {
                $brands = [['id' => null, 'company_name' => 'All brands (owner)', 'domain' => null, 'slug' => null]];
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
                'account_status' => $user->status === 'active' ? 'active' : ($user->suspended_at ? 'suspended' : 'inactive'),
                'invitation_status' => $user->invitation_status ?? ($user->last_login_at ? 'accepted' : 'none'),
                'invited_at' => $user->invited_at,
                'last_login_at' => $user->last_login_at,
                'last_active_at' => $user->last_login_at,
                'two_factor_status' => 'not_yet_implemented',
                'is_developer' => (bool) $user->is_developer,
                'is_test_data' => (bool) ($user->is_test_data ?? false),
                'active_token_count' => (int) ($user->tokens_count ?? 0),
                'brand_scope' => $brands,
                'linked_profiles' => [
                    'stripe_account_id' => $user->stripe_account_id,
                    'stripe_onboarding_status' => $user->stripe_onboarding_status,
                    'stripe_payout_ready' => (bool) $user->stripe_payout_ready,
                    'contractor' => $user->contractor ? [
                        'id' => $user->contractor->id,
                        'legal_name' => $user->contractor->legal_name,
                        'operating_name' => $user->contractor->operating_name,
                        'approval_status' => $user->contractor->approval_status,
                        'wcb_status' => $user->contractor->wcb_status,
                        'liability_insurance_status' => $user->contractor->liability_insurance_status,
                    ] : null,
                    'content_editor_brand_id' => $user->brand_id,
                ],
                'created_at' => $user->created_at,
            ];
        });

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:pm,contractor',
            'password' => 'nullable|string|min:8',
            'send_invite' => 'nullable|boolean',
        ]);

        $password = $data['password'] ?? Str::random(12);
        $sendInvite = (bool) ($data['send_invite'] ?? true);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $password,
            'role' => $data['role'],
            'status' => 'active',
            'invited_at' => $sendInvite ? now() : null,
            'invitation_status' => $sendInvite ? 'pending' : 'none',
        ]);

        if ($data['role'] === 'contractor') {
            Contractor::create([
                'user_id' => $user->id,
                'legal_name' => $data['name'],
                'operating_name' => $data['name'],
                'contact_name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'wcb_status' => 'not_uploaded',
                'liability_insurance_status' => 'not_uploaded',
                'approval_status' => 'pending',
                'state' => 'profile_incomplete',
                'services' => [],
                'cities' => [],
            ]);
        }

        if ($sendInvite && $user->phone) {
            $this->sendInviteSms($user, $password);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_created',
            'new_value' => [
                'role' => $data['role'],
                'email' => $data['email'],
                'invitation_status' => $user->invitation_status,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Account created successfully',
            'user' => $user->only(['id', 'name', 'email', 'phone', 'role', 'invitation_status']),
            'password' => $password,
        ], 201);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        return $this->suspend($request, $user);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin', 'external_review_ai', 'learning_ai'], true)) {
            return response()->json(['message' => 'Cannot suspend this account type'], 422);
        }

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Cannot suspend your own account'], 422);
        }

        $previous = [
            'status' => $user->status,
            'suspended_at' => $user->suspended_at,
        ];

        $user->update([
            'status' => 'inactive',
            'suspended_at' => now(),
        ]);

        // A-14: immediately kill Sanctum tokens / API access.
        $user->tokens()->delete();

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_suspended',
            'previous_value' => $previous,
            'new_value' => [
                'status' => 'inactive',
                'suspended_at' => now()->toIso8601String(),
                'tokens_revoked' => true,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Account suspended; active sessions revoked.']);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin', 'external_review_ai', 'learning_ai'], true)) {
            return response()->json(['message' => 'Cannot change this account type'], 422);
        }

        $previous = [
            'status' => $user->status,
            'suspended_at' => $user->suspended_at,
        ];

        $user->update([
            'status' => 'active',
            'suspended_at' => null,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_reactivated',
            'previous_value' => $previous,
            'new_value' => [
                'status' => 'active',
                'suspended_at' => null,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Account reactivated.']);
    }

    public function resendInvite(Request $request, User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin', 'external_review_ai', 'learning_ai'], true)) {
            return response()->json(['message' => 'Cannot invite this account type'], 422);
        }

        $newPassword = Str::random(12);
        $user->update([
            'password' => $newPassword,
            'invited_at' => now(),
            'invitation_status' => 'pending',
            'status' => 'active',
            'suspended_at' => null,
        ]);
        $user->tokens()->delete();

        $smsSent = false;
        if ($user->phone) {
            $smsSent = $this->sendInviteSms($user, $newPassword);
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_invite_resent',
            'new_value' => [
                'invitation_status' => 'pending',
                'sms_sent' => $smsSent,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Invite resent; previous sessions revoked.',
            'password' => $newPassword,
            'sms_sent' => $smsSent,
        ]);
    }

    public function setDeveloper(Request $request, User $user): JsonResponse
    {
        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Developer permission is only available for owners.'], 422);
        }

        $data = $request->validate([
            'is_developer' => 'required|boolean',
            'current_password' => 'required|string',
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'Password confirmation required.', 'errors' => [
                'current_password' => ['Re-enter your password to change developer access.'],
            ]], 422);
        }

        $previous = ['is_developer' => (bool) $user->is_developer];
        $user->update(['is_developer' => (bool) $data['is_developer']]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_developer_flag_changed',
            'previous_value' => $previous,
            'new_value' => [
                'is_developer' => (bool) $data['is_developer'],
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Developer permission updated.',
            'user' => ['id' => $user->id, 'is_developer' => (bool) $user->is_developer],
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        if (in_array($user->role, ['owner', 'ai_super_admin', 'external_review_ai', 'learning_ai'], true)) {
            return response()->json(['message' => 'Cannot reset password for this account type'], 422);
        }

        $newPassword = Str::random(10);
        $user->update(['password' => $newPassword]);
        $user->tokens()->delete();

        $smsSent = false;
        if ($user->phone) {
            try {
                app(SmsService::class)->send(
                    $user->phone,
                    'Hi '.$user->name.', your '.BrandResolver::PLATFORM_NAME.' password has been reset. '.
                    "New password: {$newPassword} — please log in and change it.",
                    'password_reset',
                    $user->id,
                    null
                );
                $smsSent = true;
            } catch (\Throwable) {
            }
        }

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'user',
            'object_id' => $user->id,
            'action_type' => 'user_password_reset',
            'new_value' => [
                'tokens_revoked' => true,
                'sms_sent' => $smsSent,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Password reset successfully',
            'password' => $newPassword,
            'sms_sent' => $smsSent,
        ]);
    }

    private function sendInviteSms(User $user, string $password): bool
    {
        $roleLabel = $user->role === 'pm' ? 'Project Manager' : 'Contractor';
        try {
            app(SmsService::class)->send(
                $user->phone,
                'Hi '.$user->name.', your '.BrandResolver::PLATFORM_NAME." {$roleLabel} account has been created. ".
                'Login at: https://serviceop-vbstp.ondigitalocean.app '.
                "Email: {$user->email} / Password: {$password}",
                'account_created',
                $user->id,
                null
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
