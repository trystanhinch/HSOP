<?php

namespace App\Services\Staging;

use RuntimeException;

/**
 * Milestone 6A.2 — fail-closed staging isolation checks.
 */
class StagingIsolationGuard
{
    /**
     * Boot-time hard stop: never allow a live Stripe secret while staging_mode is on.
     */
    public function assertBootSafe(): void
    {
        if (! config('app.staging_mode')) {
            return;
        }

        $secret = (string) config('payment.stripe.secret', '');
        if ($this->isLiveStripeSecret($secret)) {
            throw new RuntimeException(
                'STAGING FAIL-CLOSED: payment.stripe.secret looks like a LIVE Stripe key (sk_live_*). '
                .'Refusing to boot. Use a sk_test_* key (or PAYMENT_PROVIDER=mock) on staging.'
            );
        }
    }

    /**
     * @return list<array{level: string, code: string, message: string}>
     */
    public function verify(): array
    {
        $issues = [];

        if (! config('app.staging_mode')) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'staging_mode_false',
                'message' => 'STAGING_MODE is not true. Isolation guarantees do not apply.',
            ];
        }

        if (app()->environment('production')) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'env_production',
                'message' => 'APP_ENV is production — staging isolation tooling must not run here.',
            ];
        }

        $secret = (string) config('payment.stripe.secret', '');
        if ($this->isLiveStripeSecret($secret)) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'stripe_live_key',
                'message' => 'Stripe secret starts with sk_live_ — production key detected.',
            ];
        } elseif ($secret !== '' && ! str_starts_with($secret, 'sk_test_') && config('payment.provider') === 'stripe') {
            $issues[] = [
                'level' => 'warn',
                'code' => 'stripe_key_unrecognized',
                'message' => 'Stripe secret set but does not start with sk_test_ (and is not sk_live_). Confirm it is a test key.',
            ];
        }

        $smsEnabled = (bool) config('services.sms.enabled', false);
        $twilioSid = (string) config('services.sms.sid', '');
        if ($smsEnabled) {
            $allowed = config('staging.allowed_twilio_sids', []);
            if ($twilioSid !== '' && $allowed !== [] && in_array($twilioSid, $allowed, true)) {
                $issues[] = [
                    'level' => 'warn',
                    'code' => 'sms_enabled_allowlisted',
                    'message' => 'SMS_ENABLED=true with an allowlisted Twilio SID. Prefer SMS_ENABLED=false on staging.',
                ];
            } else {
                $issues[] = [
                    'level' => 'fail',
                    'code' => 'sms_enabled_unsafe',
                    'message' => 'SMS_ENABLED=true on staging without an allowlisted TWILIO_SID. '
                        .'Twilio Account SIDs do not encode live vs test; keep SMS disabled or set STAGING_ALLOWED_TWILIO_SIDS.',
                ];
            }
        }

        $mailer = (string) config('mail.default', 'log');
        $allowedMailers = config('staging.allowed_mail_mailers', ['log', 'array']);
        if (! in_array($mailer, $allowedMailers, true)) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'mail_not_sandbox',
                'message' => "MAIL_MAILER={$mailer} is not a staging-safe mailer (allowed: ".implode(', ', $allowedMailers).').',
            ];
        }

        $dbHost = (string) config('database.connections.'.config('database.default').'.host', '');
        $dbName = (string) config('database.connections.'.config('database.default').'.database', '');
        $forbiddenHosts = config('staging.forbidden_production_db_hosts', []);
        $forbiddenNames = config('staging.forbidden_production_db_names', []);

        if ($dbHost !== '' && in_array($dbHost, $forbiddenHosts, true)) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'db_host_matches_production',
                'message' => "Database host '{$dbHost}' matches a configured production host identifier.",
            ];
        }
        if ($dbName !== '' && in_array($dbName, $forbiddenNames, true)) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'db_name_matches_production',
                'message' => "Database name '{$dbName}' matches a configured production database identifier.",
            ];
        }

        $user = (string) config('staging.basic_auth_user', '');
        $pass = (string) config('staging.basic_auth_password', '');
        if (config('app.staging_mode') && ($user === '' || $pass === '')) {
            $issues[] = [
                'level' => 'fail',
                'code' => 'basic_auth_unconfigured',
                'message' => 'STAGING_BASIC_AUTH_USER / STAGING_BASIC_AUTH_PASSWORD must be set when STAGING_MODE=true.',
            ];
        }

        return $issues;
    }

    public function isLiveStripeSecret(string $secret): bool
    {
        $secret = trim($secret);

        return $secret !== '' && str_starts_with($secret, 'sk_live_');
    }

    /**
     * @param  list<array{level: string, code: string, message: string}>  $issues
     */
    public function hasFailures(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === 'fail') {
                return true;
            }
        }

        return false;
    }
}
