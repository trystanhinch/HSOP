<?php

namespace App\Console\Commands;

use App\Services\ReviewGateway\ExternalReviewAiPrincipal;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Milestone 6A Phase 4 — surface (do not revoke) external_review_ai tokens nearing expiry.
 * Review Center summary also computes this live; this command is for scheduled ops visibility.
 */
class FlagExpiringReviewTokensCommand extends Command
{
    protected $signature = 'review-ai:flag-expiring-tokens';

    protected $description = 'List external_review_ai tokens nearing expiration (no auto-revoke)';

    public function handle(ExternalReviewAiPrincipal $principal): int
    {
        $warningDays = max(1, (int) config('review_gateway.token_expiry_warning_days', 14));
        $horizon = now()->addDays($warningDays);

        $rows = $principal->activeTokensQuery()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $horizon)
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'expires_at' => optional($t->expires_at)?->toDateTimeString(),
                'days_left' => $t->expires_at ? now()->diffInDays($t->expires_at, false) : null,
            ])
            ->all();

        if ($rows === []) {
            $this->info("No external_review_ai tokens expire within {$warningDays} day(s).");

            return self::SUCCESS;
        }

        $this->warn(count($rows).' token(s) nearing expiration (warning window = '.$warningDays.' days). Not revoked.');
        $this->table(['id', 'name', 'expires_at', 'days_left'], $rows);

        return self::SUCCESS;
    }
}
