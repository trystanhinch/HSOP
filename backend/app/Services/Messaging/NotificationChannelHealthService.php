<?php

namespace App\Services\Messaging;

use App\Models\EmailLog;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\BrandResolver;
use Illuminate\Support\Facades\Schema;

/**
 * A-19 — Separate desired policy from provider readiness.
 */
class NotificationChannelHealthService
{
    /**
     * @return array{sms: array<string, mixed>, email: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return [
            'sms' => $this->smsHealth(),
            'email' => $this->emailHealth(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function smsHealth(): array
    {
        $policy = Setting::isGloballyEnabled('sms');
        $envEnabled = (bool) config('services.sms.enabled', false);
        $sid = (string) config('services.sms.sid');
        $token = (string) config('services.sms.auth_token');
        $from = (string) config('services.sms.from_number');
        $sdk = class_exists(\Twilio\Rest\Client::class);
        $configured = $envEnabled && $sid !== '' && $token !== '' && $from !== '' && $sdk;
        $ready = $configured;

        $lastSuccess = null;
        $lastError = null;
        $deliveryRate = null;
        if (Schema::hasTable('sms_logs')) {
            $lastSuccess = SmsLog::query()->where('status', 'sent')->latest('id')->first(['id', 'created_at', 'provider_message_id', 'to_phone']);
            $errorCols = ['id', 'created_at', 'error_message', 'status'];
            foreach (['error_plain', 'error_code'] as $col) {
                if (Schema::hasColumn('sms_logs', $col)) {
                    $errorCols[] = $col;
                }
            }
            $lastError = SmsLog::query()
                ->whereIn('status', ['failed', 'provider_unavailable', 'disabled'])
                ->latest('id')
                ->first($errorCols);
            $window = SmsLog::query()->where('created_at', '>=', now()->subDays(30));
            $total = (clone $window)->whereIn('status', ['sent', 'failed', 'provider_unavailable'])->count();
            $sent = (clone $window)->where('status', 'sent')->count();
            $deliveryRate = $total > 0 ? round(($sent / $total) * 100, 1) : null;
        }

        $blocking = $policy && ! $ready;

        return [
            'channel' => 'sms',
            'policy_enabled' => $policy,
            'provider' => 'twilio',
            'provider_ready' => $ready,
            'provider_configured' => $configured,
            'connection_status' => $ready ? 'ready' : ($envEnabled ? 'misconfigured' : 'not_configured'),
            'verified_sender' => $from !== '' ? $from : null,
            'checks' => [
                'sms_enabled_env' => $envEnabled,
                'twilio_sid' => $sid !== '',
                'twilio_auth_token' => $token !== '',
                'twilio_from_number' => $from !== '',
                'twilio_sdk' => $sdk,
            ],
            'last_successful_send_at' => $lastSuccess?->created_at?->toIso8601String(),
            'last_error' => $lastError ? [
                'at' => $lastError->created_at?->toIso8601String(),
                'status' => $lastError->status,
                'code' => $lastError->error_code,
                'plain' => $lastError->error_plain ?: $lastError->error_message,
            ] : null,
            'delivery_rate_30d_pct' => $deliveryRate,
            'provider_balance' => null,
            'provider_balance_note' => 'Twilio balance is not queried automatically (avoids billing API side-effects).',
            'blocking_error' => $blocking
                ? 'SMS policy is ON but Twilio is not ready. Customer SMS will fail loudly until credentials (TWILIO_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER, SMS_ENABLED) are configured.'
                : null,
            'platform_note' => 'Internal platform name is '.BrandResolver::PLATFORM_NAME.'; customer copy uses Brand Content via BrandResolver.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emailHealth(): array
    {
        $policy = Setting::isGloballyEnabled('email');
        $mailer = (string) config('mail.default', 'log');
        $from = (string) config('mail.from.address');
        $resendKey = (string) config('services.resend.key');
        $smtpHost = (string) config('mail.mailers.smtp.host');

        $ready = false;
        $connection = 'not_configured';
        if ($mailer === 'resend' && $resendKey !== '' && $from !== '') {
            $ready = true;
            $connection = 'ready';
        } elseif ($mailer === 'smtp' && $smtpHost !== '' && $from !== '') {
            $ready = true;
            $connection = 'ready';
        } elseif (in_array($mailer, ['log', 'array'], true)) {
            $connection = 'dev_sink';
            $ready = app()->environment(['local', 'testing']);
        }

        $lastSuccess = null;
        $lastError = null;
        $deliveryRate = null;
        if (Schema::hasTable('email_logs')) {
            $lastSuccess = EmailLog::query()->where('status', 'sent')->latest('id')->first(['id', 'created_at', 'to_email']);
            $errorCols = ['id', 'created_at', 'error_message', 'status'];
            foreach (['error_plain', 'error_code'] as $col) {
                if (Schema::hasColumn('email_logs', $col)) {
                    $errorCols[] = $col;
                }
            }
            $lastError = EmailLog::query()
                ->whereIn('status', ['failed', 'provider_unavailable'])
                ->latest('id')
                ->first($errorCols);
            $window = EmailLog::query()->where('created_at', '>=', now()->subDays(30));
            $total = (clone $window)->whereIn('status', ['sent', 'failed', 'provider_unavailable'])->count();
            $sent = (clone $window)->where('status', 'sent')->count();
            $deliveryRate = $total > 0 ? round(($sent / $total) * 100, 1) : null;
        }

        $blocking = $policy && ! $ready;

        return [
            'channel' => 'email',
            'policy_enabled' => $policy,
            'provider' => $mailer,
            'provider_ready' => $ready,
            'connection_status' => $connection,
            'verified_sender' => $from !== '' ? $from : null,
            'checks' => [
                'mailer' => $mailer,
                'from_address' => $from !== '',
                'resend_key' => $resendKey !== '',
                'smtp_host' => $smtpHost !== '',
            ],
            'last_successful_send_at' => $lastSuccess?->created_at?->toIso8601String(),
            'last_error' => $lastError ? [
                'at' => $lastError->created_at?->toIso8601String(),
                'status' => $lastError->status,
                'code' => $lastError->error_code,
                'plain' => $lastError->error_plain ?: $lastError->error_message,
            ] : null,
            'delivery_rate_30d_pct' => $deliveryRate,
            'provider_balance' => null,
            'blocking_error' => $blocking
                ? 'Email policy is ON but the mail provider is not ready. Customer email will fail loudly until MAIL_MAILER / credentials / MAIL_FROM_ADDRESS are configured.'
                : null,
        ];
    }

    public function smsProviderReady(): bool
    {
        return (bool) ($this->smsHealth()['provider_ready'] ?? false);
    }

    public function emailProviderReady(): bool
    {
        return (bool) ($this->emailHealth()['provider_ready'] ?? false);
    }
}
