<?php

namespace App\Console\Commands;

use App\Services\Staging\StagingIsolationGuard;
use Illuminate\Console\Command;

/**
 * Milestone 6A.2 — post-provision isolation healthcheck for staging.
 */
class StagingVerifyIsolationCommand extends Command
{
    protected $signature = 'staging:verify-isolation';

    protected $description = 'Verify staging isolation: no live Stripe, safe SMS/mail, DB not prod, STAGING_MODE on';

    public function handle(StagingIsolationGuard $guard): int
    {
        $issues = $guard->verify();

        if ($issues === []) {
            $this->info('Staging isolation OK — no issues reported.');
            $this->line('staging_mode='.(config('app.staging_mode') ? 'true' : 'false'));
            $this->line('app_env='.app()->environment());
            $this->line('payment_provider='.config('payment.provider'));
            $this->line('mail_mailer='.config('mail.default'));
            $this->line('sms_enabled='.(config('services.sms.enabled') ? 'true' : 'false'));

            return self::SUCCESS;
        }

        $rows = array_map(fn ($i) => [
            strtoupper($i['level']),
            $i['code'],
            $i['message'],
        ], $issues);

        $this->table(['Level', 'Code', 'Message'], $rows);

        if ($guard->hasFailures($issues)) {
            $this->error('Staging isolation FAILED — fix the items above before using this environment.');

            return self::FAILURE;
        }

        $this->warn('Staging isolation passed with warnings.');

        return self::SUCCESS;
    }
}
