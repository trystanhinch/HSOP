<?php

namespace App\Services\LearningGateway;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6B Phase 1 — dedicated Learning AI service identity.
 * Never shares ai_super_admin or external_review_ai principals.
 */
class LearningAiPrincipal
{
    public function role(): string
    {
        return (string) config('learning_ai.actor_role', 'learning_ai');
    }

    public function email(): string
    {
        return (string) config('learning_ai.actor_email', 'learning-ai@serviceop.system');
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return config('learning_ai.abilities', [
            'learning:read',
            'learning:eligibility-write',
            'learning:evidence-write',
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
            'name' => 'Learning AI',
            'email' => $this->email(),
            'password' => Hash::make(Str::random(64)),
            'role' => $this->role(),
            'status' => 'active',
            'sms_enabled' => false,
        ]);
    }

    public function isLearningAi(?User $user): bool
    {
        return $user !== null && $user->role === $this->role();
    }

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
}
