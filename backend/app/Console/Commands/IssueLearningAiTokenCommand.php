<?php

namespace App\Console\Commands;

use App\Services\LearningGateway\LearningAiPrincipal;
use Illuminate\Console\Command;

/**
 * Milestone 6B Phase 1 — mint a Sanctum token on the dedicated learning_ai principal.
 */
class IssueLearningAiTokenCommand extends Command
{
    protected $signature = 'learning-ai:issue-token
                            {name : Human-readable token name (stored; plaintext printed once)}
                            {--ttl= : Override default TTL in days (config learning_ai.token_default_ttl_days)}
                            {--email= : Override actor email (default from config)}';

    protected $description = 'Mint a Sanctum token scoped to learning:* on learning_ai (plaintext shown once)';

    public function handle(LearningAiPrincipal $principal): int
    {
        $name = (string) $this->argument('name');
        $emailOpt = $this->option('email');
        if (is_string($emailOpt) && $emailOpt !== '') {
            config(['learning_ai.actor_email' => $emailOpt]);
        }

        $user = $principal->findOrCreate();
        if ($user->wasRecentlyCreated) {
            $this->warn("Created learning_ai user {$user->email}.");
        }

        $ttlDays = $this->option('ttl');
        $ttlDays = $ttlDays !== null && $ttlDays !== ''
            ? max(1, (int) $ttlDays)
            : max(1, (int) config('learning_ai.token_default_ttl_days', 90));

        $expiresAt = now()->addDays($ttlDays);
        $abilities = $principal->abilities();

        $newToken = $user->createToken($name, $abilities, $expiresAt);
        $plain = $newToken->plainTextToken;

        $this->info('Learning AI token minted. Store this secret now — it will not be shown again.');
        $this->line('token_id='.$newToken->accessToken->id);
        $this->line('token_name='.$name);
        $this->line('actor_user_id='.$user->id);
        $this->line('actor_role='.$user->role);
        $this->line('expires_at='.$expiresAt->toIso8601String());
        $this->line('ttl_days='.$ttlDays);
        $this->line('abilities='.implode(',', $abilities));
        $this->newLine();
        $this->line($plain);

        return self::SUCCESS;
    }
}
