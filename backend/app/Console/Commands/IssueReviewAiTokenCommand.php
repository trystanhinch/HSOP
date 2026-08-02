<?php

namespace App\Console\Commands;

use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use Illuminate\Console\Command;

/**
 * Milestone 6A Phase 4 — mint a Sanctum token on the dedicated external_review_ai principal.
 */
class IssueReviewAiTokenCommand extends Command
{
    protected $signature = 'review-ai:issue-token
                            {name : Human-readable token name (stored; plaintext printed once)}
                            {--ttl= : Override default TTL in days (config review_gateway.token_default_ttl_days)}
                            {--email= : Override actor email (default from config)}';

    protected $description = 'Mint a Sanctum token scoped to review:* on external_review_ai (plaintext shown once)';

    public function handle(ExternalReviewAiPrincipal $principal): int
    {
        $name = (string) $this->argument('name');
        $emailOpt = $this->option('email');
        if (is_string($emailOpt) && $emailOpt !== '') {
            config(['review_gateway.actor_email' => $emailOpt]);
        }

        $user = $principal->findOrCreate();
        if ($user->wasRecentlyCreated) {
            $this->warn("Created external_review_ai user {$user->email}.");
        }

        $ttlDays = $this->option('ttl');
        $ttlDays = $ttlDays !== null && $ttlDays !== ''
            ? max(1, (int) $ttlDays)
            : max(1, (int) config('review_gateway.token_default_ttl_days', 90));

        $expiresAt = now()->addDays($ttlDays);
        $abilities = $principal->abilities();

        $newToken = $user->createToken($name, $abilities, $expiresAt);
        $plain = $newToken->plainTextToken;

        $this->info('Review AI token minted. Store this secret now — it will not be shown again.');
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
