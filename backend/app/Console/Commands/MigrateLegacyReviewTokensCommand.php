<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6A Phase 4 — list (and optionally revoke) review:* tokens not on external_review_ai.
 * Never auto-revokes without --revoke.
 */
class MigrateLegacyReviewTokensCommand extends Command
{
    protected $signature = 'review-ai:migrate-legacy-tokens
                            {--revoke : Actually delete listed legacy tokens (required to act)}';

    protected $description = 'List review:* tokens issued outside external_review_ai; revoke only with --revoke';

    public function handle(ExternalReviewAiPrincipal $principal): int
    {
        $legacy = $principal->legacyTokensQuery()
            ->orderBy('id')
            ->get();

        if ($legacy->isEmpty()) {
            $this->info('No legacy review tokens found (all review:* tokens are on external_review_ai, or none exist).');

            return self::SUCCESS;
        }

        $rows = $legacy->map(function (PersonalAccessToken $t) {
            $user = User::query()->find($t->tokenable_id);

            return [
                'id' => $t->id,
                'name' => $t->name,
                'tokenable_id' => $t->tokenable_id,
                'user_email' => $user?->email ?? '(missing)',
                'user_role' => $user?->role ?? '(missing)',
                'expires_at' => optional($t->expires_at)?->toDateTimeString() ?? 'null',
                'created_at' => optional($t->created_at)?->toDateTimeString(),
            ];
        })->all();

        $this->table(
            ['id', 'name', 'tokenable_id', 'user_email', 'user_role', 'expires_at', 'created_at'],
            $rows
        );
        $this->warn('Found '.$legacy->count().' legacy review token(s). Re-issue via review-ai:issue-token, then revoke these.');

        if (! $this->option('revoke')) {
            $this->line('Dry run only — pass --revoke to delete the listed tokens.');

            return self::SUCCESS;
        }

        $ids = $legacy->pluck('id')->all();
        PersonalAccessToken::query()->whereIn('id', $ids)->delete();
        $this->info('Revoked '.count($ids).' legacy token(s): '.implode(', ', $ids));

        return self::SUCCESS;
    }
}
