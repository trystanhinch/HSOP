<?php

namespace Tests\Feature\ReviewGateway;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared helpers for Review Gateway tests (Phase 4 identity).
 */
trait CreatesExternalReviewAiActor
{
    /**
     * @return array{0: User, 1: string, 2: PersonalAccessToken|null}
     */
    protected function makeExternalReviewActor(?array $abilities = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $abilities ??= config('review_gateway.abilities');
        $email = config('review_gateway.actor_email', 'external-review-ai@serviceop.system');
        $role = config('review_gateway.actor_role', 'external_review_ai');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'External Review AI',
                'password' => Hash::make(Str::random(40)),
                'role' => $role,
                'status' => 'active',
                'sms_enabled' => false,
            ]
        );
        $user->forceFill(['role' => $role, 'status' => 'active'])->save();

        $expiresAt ??= now()->addDays((int) config('review_gateway.token_default_ttl_days', 90));
        $new = $user->createToken('test-review-'.Str::random(6), $abilities, $expiresAt);

        return [$user, $new->plainTextToken, $new->accessToken];
    }

    protected function makeAiSuperAdminUser(): User
    {
        $user = User::firstOrCreate(
            ['email' => 'ai-super-admin@serviceop.system'],
            [
                'name' => 'AI Super Admin',
                'password' => Hash::make(Str::random(40)),
                'role' => 'ai_super_admin',
                'status' => 'active',
                'sms_enabled' => false,
            ]
        );
        $user->forceFill(['role' => 'ai_super_admin', 'status' => 'active'])->save();

        return $user;
    }

    protected function reviewAuthHeaders(string $plain): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    protected function ensureExternalReviewRoleMigrated(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_31_000001_add_external_review_ai_role.php',
            '--force' => true,
        ]);
    }
}
