<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Customers\CommunicationGuard;
use App\Services\Messaging\DeliveryErrorTranslator;
use App\Services\Messaging\NotificationChannelHealthService;
use App\Services\Messaging\NotificationFailureEscalationService;
use App\Services\TestData\TestDataGuard;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected $client;

    protected ?string $from;

    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('services.sms.enabled', false);
        $this->from = config('services.sms.from_number');

        if ($this->enabled && config('services.sms.sid') && class_exists(\Twilio\Rest\Client::class)) {
            $this->client = new \Twilio\Rest\Client(
                config('services.sms.sid'),
                config('services.sms.auth_token')
            );
        }
    }

    public static function phoneForUser(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($user->phone) {
            return $user->phone;
        }

        $user->loadMissing(['customer', 'contractor']);

        return $user->customer?->phone ?? $user->contractor?->phone;
    }

    /**
     * @param  array<string, mixed>  $meta  retry_of_id, idempotency_key, attempt_count, related_lead_id, brand_id, is_critical
     */
    public function send(?string $toPhone, string $message, string $triggerEvent, $userId = null, $jobId = null, array $meta = []): array
    {
        // A-16: inactive templates yield empty body — do not send.
        if (trim($message) === '') {
            return ['success' => false, 'reason' => 'template_inactive', 'detail' => 'Empty or inactive template'];
        }

        $toPhone = $this->formatPhone($toPhone);
        $translator = app(DeliveryErrorTranslator::class);
        $isCritical = (bool) ($meta['is_critical'] ?? in_array($triggerEvent, NotificationFailureEscalationService::CRITICAL_EVENTS, true));

        $guard = app(TestDataGuard::class)->checkOutbound(
            userId: $userId ? (int) $userId : null,
            jobId: $jobId ? (int) $jobId : null,
            phone: $toPhone,
        );
        if ($guard['blocked']) {
            $t = $translator->translate('test_data', $guard['reason']);
            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'blocked_test_data', $t, $meta, true));

            return ['success' => false, 'reason' => 'test_data', 'detail' => $guard['reason'], 'plain' => $t['plain']];
        }

        $comm = app(CommunicationGuard::class)->checkSms(
            userId: $userId ? (int) $userId : null,
            phone: $toPhone,
        );
        if ($comm['blocked']) {
            $t = $translator->translate('do_not_contact', $comm['reason']);
            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'blocked_do_not_contact', $t, $meta));

            return ['success' => false, 'reason' => 'do_not_contact', 'detail' => $comm['reason'], 'plain' => $t['plain']];
        }

        if (! Setting::isGloballyEnabled('sms')) {
            $t = $translator->translate('sms_disabled');
            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'disabled', $t, $meta));

            return ['success' => false, 'reason' => 'sms_disabled', 'plain' => $t['plain']];
        }

        if (! $toPhone) {
            $t = $translator->translate('no_phone');
            $this->writeLog($this->logPayload('MISSING_OR_INVALID', $userId, $triggerEvent, $jobId, $message, 'failed', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return ['success' => false, 'reason' => 'no_phone', 'plain' => $t['plain'], 'correction_path' => $t['correction_path']];
        }

        // A-19: policy ON but provider not ready → loud failure, not silent.
        $health = app(NotificationChannelHealthService::class);
        if (! $health->smsProviderReady() || ! $this->enabled || ! $this->client) {
            $t = $translator->translate('provider_unavailable', $health->smsHealth()['blocking_error'] ?? null);
            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'provider_unavailable', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return [
                'success' => false,
                'reason' => 'provider_unavailable',
                'plain' => $t['plain'],
                'blocking_error' => $health->smsHealth()['blocking_error'],
                'correction_path' => $t['correction_path'],
            ];
        }

        if ($userId) {
            $user = User::find($userId);
            if ($user && $user->sms_enabled === false) {
                $t = $translator->translate('user_disabled');
                $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'disabled', $t, $meta));

                return ['success' => false, 'reason' => 'user_disabled', 'plain' => $t['plain']];
            }
        }

        // Idempotency: if this key already sent, do not duplicate.
        if (! empty($meta['idempotency_key'])) {
            $prior = SmsLog::withTestData()
                ->where('idempotency_key', $meta['idempotency_key'])
                ->where('status', 'sent')
                ->first();
            if ($prior) {
                return [
                    'success' => true,
                    'sid' => $prior->provider_message_id,
                    'deduplicated' => true,
                ];
            }
        }

        try {
            $result = $this->client->messages->create($toPhone, [
                'from' => $this->from,
                'body' => $message,
            ]);

            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'sent', [
                'code' => null,
                'plain' => null,
                'correction_path' => null,
            ], $meta, false, $result->sid));

            return ['success' => true, 'sid' => $result->sid];
        } catch (\Exception $e) {
            Log::error('SMS send failed', ['error' => $e->getMessage(), 'to' => $toPhone]);
            $t = $translator->translate('send_failed', $e->getMessage());
            $this->writeLog($this->logPayload($toPhone, $userId, $triggerEvent, $jobId, $message, 'failed', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return [
                'success' => false,
                'reason' => 'send_failed',
                'error' => $e->getMessage(),
                'plain' => $t['plain'],
                'correction_path' => $t['correction_path'],
            ];
        }
    }

    public function sendToUser(?User $user, string $message, string $triggerEvent, ?int $jobId = null, array $meta = []): array
    {
        return $this->send(
            self::phoneForUser($user),
            $message,
            $triggerEvent,
            $user?->id,
            $jobId,
            $meta
        );
    }

    private function formatPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+'.$digits;
        }

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (str_starts_with($phone, '+') && strlen($digits) >= 10) {
            return '+'.$digits;
        }

        Log::warning('SmsService: unrecognizable phone format', [
            'original' => $phone,
            'digits' => $digits,
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $translated
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function logPayload(
        ?string $toPhone,
        $userId,
        string $triggerEvent,
        $jobId,
        string $message,
        string $status,
        array $translated,
        array $meta,
        bool $isTest = false,
        ?string $providerId = null
    ): array {
        return [
            'to_phone' => $toPhone ?: 'MISSING_OR_INVALID',
            'recipient_normalized' => $toPhone && $toPhone !== 'MISSING_OR_INVALID' ? $toPhone : null,
            'user_id' => $userId,
            'trigger_event' => $triggerEvent,
            'related_job_id' => $jobId,
            'related_lead_id' => $meta['related_lead_id'] ?? null,
            'brand_id' => $meta['brand_id'] ?? null,
            'message_body' => $message,
            'status' => $status,
            'provider_message_id' => $providerId,
            'error_message' => $translated['plain'] ?? null,
            'error_code' => $translated['code'] ?? null,
            'error_plain' => $translated['plain'] ?? null,
            'correction_path' => $translated['correction_path'] ?? null,
            'attempt_count' => (int) ($meta['attempt_count'] ?? 1),
            'retry_of_id' => $meta['retry_of_id'] ?? null,
            'idempotency_key' => $meta['idempotency_key'] ?? null,
            'is_critical' => (bool) ($meta['is_critical'] ?? false),
            'is_test_data' => $isTest,
        ];
    }

    private function writeLog(array $data): void
    {
        try {
            if (($data['status'] ?? null) === 'blocked_test_data') {
                $data['is_test_data'] = true;
            }
            SmsLog::create($data);
        } catch (\Exception $e) {
            Log::warning('SmsLog write failed', [
                'error' => $e->getMessage(),
                'trigger' => $data['trigger_event'] ?? null,
                'to' => $data['to_phone'] ?? null,
            ]);
        }
    }

    private function escalateIfNeeded(string $triggerEvent, string $plain, $jobId, $leadId, $userId, bool $isCritical): void
    {
        if (! $isCritical) {
            return;
        }
        app(NotificationFailureEscalationService::class)->maybeEscalate(
            'sms',
            $triggerEvent,
            $plain,
            $jobId ? (int) $jobId : null,
            $leadId ? (int) $leadId : null,
            $userId ? (int) $userId : null,
        );
    }
}
