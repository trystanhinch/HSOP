<?php

namespace App\Services\ReviewGateway;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6A Phase 4 — dedicated External Review AI service identity helpers.
 * Never shares the ai_super_admin principal.
 */
class ExternalReviewAiPrincipal
{
    public function role(): string
    {
        return (string) config('review_gateway.actor_role', 'external_review_ai');
    }

    public function email(): string
    {
        return (string) config('review_gateway.actor_email', 'external-review-ai@serviceop.system');
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return config('review_gateway.abilities', [
            'review:read',
            'review:code-read',
            'review:evidence-write',
        ]);
    }

    public function find(): ?User
    {
        return User::query()
            ->where('email', $this->email())
            ->where('role', $this->role())
            ->first();
    }

    public function findOrCreate(): User
    {
        $user = $this->find();
        if ($user) {
            return $user;
        }

        return User::create([
            'name' => 'External Review AI',
            'email' => $this->email(),
            'password' => Hash::make(Str::random(64)),
            'role' => $this->role(),
            'status' => 'active',
            'sms_enabled' => false,
        ]);
    }

    public function isExternalReviewAi(?User $user): bool
    {
        return $user !== null && $user->role === $this->role();
    }

    /**
     * Tokens belonging to external_review_ai users that carry any review:* ability.
     */
    public function activeTokensQuery(): Builder
    {
        $abilities = $this->abilities();
        $role = $this->role();

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::query()->where('role', $role)->select('id'))
            ->where(function ($q) use ($abilities) {
                foreach ($abilities as $ability) {
                    $q->orWhere('abilities', 'like', '%"'.$ability.'"%');
                }
            });
    }

    /**
     * Review-ability tokens NOT on external_review_ai (legacy Phase 1–3 / mis-issued).
     */
    public function legacyTokensQuery(): Builder
    {
        $abilities = $this->abilities();
        $role = $this->role();

        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where(function ($q) use ($abilities) {
                foreach ($abilities as $ability) {
                    $q->orWhere('abilities', 'like', '%"'.$ability.'"%');
                }
            })
            ->whereNotIn('tokenable_id', User::query()->where('role', $role)->select('id'));
    }
}
