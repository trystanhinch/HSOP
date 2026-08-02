<?php

namespace App\Services;

use App\Mail\HsopNotificationMail;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Services\Customers\CommunicationGuard;
use App\Services\Messaging\DeliveryErrorTranslator;
use App\Services\Messaging\NotificationChannelHealthService;
use App\Services\Messaging\NotificationFailureEscalationService;
use App\Services\TestData\TestDataGuard;
use App\Support\CorrelationId;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(
        ?string $toEmail,
        string $subject,
        string $view,
        array $viewData,
        string $triggerEvent,
        $userId = null,
        $jobId = null,
        array $meta = []
    ): array {
        return $this->dispatch(
            $toEmail,
            $subject,
            fn () => Mail::to($toEmail)->send(
                $this->withCorrelationHeader(new HsopNotificationMail($subject, $view, $viewData))
            ),
            $triggerEvent,
            $userId,
            $jobId,
            array_merge($meta, [
                'subject' => $meta['subject'] ?? $subject,
                'message_body' => $meta['message_body'] ?? ($viewData['body'] ?? null),
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function sendMailable(
        ?string $toEmail,
        Mailable $mailable,
        string $triggerEvent,
        $userId = null,
        $jobId = null,
        array $meta = []
    ): array {
        return $this->dispatch(
            $toEmail,
            $meta['subject'] ?? class_basename($mailable),
            fn () => Mail::to($toEmail)->send($this->withCorrelationHeader($mailable)),
            $triggerEvent,
            $userId,
            $jobId,
            $meta
        );
    }

    /**
     * @param  callable(): void  $sender
     * @param  array<string, mixed>  $meta
     */
    private function dispatch(
        ?string $toEmail,
        string $subject,
        callable $sender,
        string $triggerEvent,
        $userId,
        $jobId,
        array $meta
    ): array {
        $translator = app(DeliveryErrorTranslator::class);
        $isCritical = (bool) ($meta['is_critical'] ?? in_array($triggerEvent, NotificationFailureEscalationService::CRITICAL_EVENTS, true));
        $normalized = $toEmail ? strtolower(trim($toEmail)) : null;

        $guard = app(TestDataGuard::class)->checkOutbound(
            userId: $userId ? (int) $userId : null,
            jobId: $jobId ? (int) $jobId : null,
            email: $toEmail,
        );
        if ($guard['blocked']) {
            $t = $translator->translate('test_data', $guard['reason'], 'email');
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'blocked_test_data', $t, $meta, true));

            return ['success' => false, 'reason' => 'test_data', 'detail' => $guard['reason'], 'plain' => $t['plain']];
        }

        $comm = app(CommunicationGuard::class)->checkEmail(
            userId: $userId ? (int) $userId : null,
            email: $toEmail,
        );
        if ($comm['blocked']) {
            $t = $translator->translate('do_not_contact', $comm['reason'], 'email');
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'blocked_do_not_contact', $t, $meta));

            return ['success' => false, 'reason' => 'do_not_contact', 'detail' => $comm['reason'], 'plain' => $t['plain']];
        }

        if (! Setting::isGloballyEnabled('email')) {
            $t = $translator->translate('email_disabled', null, 'email');
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'failed', $t, $meta));

            return ['success' => false, 'reason' => 'email_disabled', 'plain' => $t['plain']];
        }

        if (! $toEmail) {
            $t = $translator->translate('no_email', null, 'email');
            $this->writeLog($this->logPayload('MISSING', null, $userId, $triggerEvent, $jobId, 'failed', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return ['success' => false, 'reason' => 'no_email', 'plain' => $t['plain'], 'correction_path' => $t['correction_path']];
        }

        $health = app(NotificationChannelHealthService::class);
        if (! $health->emailProviderReady()) {
            $t = $translator->translate('provider_unavailable', $health->emailHealth()['blocking_error'] ?? null, 'email');
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'provider_unavailable', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return [
                'success' => false,
                'reason' => 'provider_unavailable',
                'plain' => $t['plain'],
                'blocking_error' => $health->emailHealth()['blocking_error'],
                'correction_path' => $t['correction_path'],
            ];
        }

        if (! empty($meta['idempotency_key'])) {
            $prior = EmailLog::withTestData()
                ->where('idempotency_key', $meta['idempotency_key'])
                ->where('status', 'sent')
                ->first();
            if ($prior) {
                return ['success' => true, 'deduplicated' => true];
            }
        }

        try {
            Log::info('Email send attempt', [
                'to' => $toEmail,
                'trigger_event' => $triggerEvent,
                'correlation_id' => CorrelationId::current(),
            ]);
            $sender();
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'sent', [
                'code' => null,
                'plain' => null,
                'correction_path' => null,
            ], $meta));

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Email send failed', [
                'error' => $e->getMessage(),
                'to' => $toEmail,
                'correlation_id' => CorrelationId::current(),
            ]);
            $t = $translator->translate('send_failed', $e->getMessage(), 'email');
            $this->writeLog($this->logPayload($toEmail, $normalized, $userId, $triggerEvent, $jobId, 'failed', $t, $meta));
            $this->escalateIfNeeded($triggerEvent, $t['plain'], $jobId, $meta['related_lead_id'] ?? null, $userId, $isCritical);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'reason' => 'send_failed',
                'plain' => $t['plain'],
                'correction_path' => $t['correction_path'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $translated
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function logPayload(
        ?string $toEmail,
        ?string $normalized,
        $userId,
        string $triggerEvent,
        $jobId,
        string $status,
        array $translated,
        array $meta,
        bool $isTest = false
    ): array {
        return [
            'to_email' => $toEmail ?: 'MISSING',
            'recipient_normalized' => $normalized,
            'user_id' => $userId,
            'trigger_event' => $triggerEvent,
            'related_job_id' => $jobId,
            'related_lead_id' => $meta['related_lead_id'] ?? null,
            'brand_id' => $meta['brand_id'] ?? null,
            'subject' => $meta['subject'] ?? null,
            'message_body' => $meta['message_body'] ?? null,
            'status' => $status,
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
            EmailLog::create($data);
        } catch (\Exception $e) {
            Log::warning('EmailLog write failed', [
                'error' => $e->getMessage(),
                'trigger' => $data['trigger_event'] ?? null,
                'to' => $data['to_email'] ?? null,
            ]);
        }
    }

    private function withCorrelationHeader(Mailable $mailable): Mailable
    {
        $cid = CorrelationId::current();
        if (! $cid) {
            return $mailable;
        }

        return $mailable->withSymfonyMessage(function ($message) use ($cid) {
            $message->getHeaders()->addTextHeader('X-Correlation-Id', $cid);
        });
    }

    private function escalateIfNeeded(string $triggerEvent, string $plain, $jobId, $leadId, $userId, bool $isCritical): void
    {
        if (! $isCritical) {
            return;
        }
        app(NotificationFailureEscalationService::class)->maybeEscalate(
            'email',
            $triggerEvent,
            $plain,
            $jobId ? (int) $jobId : null,
            $leadId ? (int) $leadId : null,
            $userId ? (int) $userId : null,
        );
    }
}
