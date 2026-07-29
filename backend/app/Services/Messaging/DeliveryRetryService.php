<?php

namespace App\Services\Messaging;

use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SmsService;
use Illuminate\Validation\ValidationException;

/**
 * A-21 — Correct recipient + idempotent retry.
 */
class DeliveryRetryService
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email,
        protected DeliveryErrorTranslator $translator,
    ) {}

    /**
     * @param  array{phone?: string|null, email?: string|null}  $correction
     * @return array<string, mixed>
     */
    public function retrySms(SmsLog $log, User $actor, array $correction = []): array
    {
        if ($log->status === 'sent') {
            throw ValidationException::withMessages(['status' => 'This SMS already sent successfully — no retry needed.']);
        }

        $idempotencyKey = 'sms-retry-'.$log->id;
        $existing = SmsLog::withTestData()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'sent')
            ->first();
        if ($existing) {
            return [
                'success' => true,
                'deduplicated' => true,
                'message' => 'Retry already succeeded earlier — no duplicate send.',
                'log' => $existing,
            ];
        }

        $pending = SmsLog::withTestData()
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', ['sent', 'queued'])
            ->exists();
        if ($pending) {
            return [
                'success' => true,
                'deduplicated' => true,
                'message' => 'Retry already in progress or completed.',
            ];
        }

        $phone = $correction['phone'] ?? null;
        if ($phone && $log->user_id) {
            $user = User::find($log->user_id);
            if ($user) {
                $user->update(['phone' => $phone]);
            }
        }

        $to = $phone ?: $log->to_phone;
        if (! $to || $to === 'MISSING_OR_INVALID') {
            throw ValidationException::withMessages([
                'phone' => 'Provide a corrected phone number before retrying.',
            ]);
        }

        $attempt = (int) ($log->attempt_count ?? 1) + 1;

        $result = $this->sms->send(
            $to,
            (string) $log->message_body,
            (string) $log->trigger_event,
            $log->user_id,
            $log->related_job_id,
            [
                'retry_of_id' => $log->id,
                'idempotency_key' => $idempotencyKey,
                'attempt_count' => $attempt,
                'related_lead_id' => $log->related_lead_id,
                'brand_id' => $log->brand_id,
                'is_critical' => (bool) $log->is_critical,
            ]
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'deduplicated' => false,
            'provider_response' => $result,
            'actor_id' => $actor->id,
        ];
    }

    /**
     * @param  array{email?: string|null}  $correction
     * @return array<string, mixed>
     */
    public function retryEmail(EmailLog $log, User $actor, array $correction = []): array
    {
        if ($log->status === 'sent') {
            throw ValidationException::withMessages(['status' => 'This email already sent successfully — no retry needed.']);
        }

        $idempotencyKey = 'email-retry-'.$log->id;
        $existing = EmailLog::withTestData()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'sent')
            ->first();
        if ($existing) {
            return [
                'success' => true,
                'deduplicated' => true,
                'message' => 'Retry already succeeded earlier — no duplicate send.',
                'log' => $existing,
            ];
        }

        $email = $correction['email'] ?? null;
        if ($email && $log->user_id) {
            $user = User::find($log->user_id);
            if ($user) {
                $user->update(['email' => $email]);
            }
        }

        $to = $email ?: $log->to_email;
        if (! $to || $to === 'MISSING') {
            throw ValidationException::withMessages([
                'email' => 'Provide a corrected email before retrying.',
            ]);
        }

        // Email retries re-send a simple notification body from the stored subject/body if present.
        $subject = $log->subject ?: ('Retry: '.$log->trigger_event);
        $body = $log->message_body ?: 'This is a re-send of a previously failed notification.';

        $attempt = (int) ($log->attempt_count ?? 1) + 1;

        $result = $this->email->send(
            $to,
            $subject,
            'emails.notification',
            [
                'title' => $subject,
                'body' => $body,
                'actionUrl' => null,
                'actionText' => null,
            ],
            (string) $log->trigger_event,
            $log->user_id,
            $log->related_job_id,
            [
                'retry_of_id' => $log->id,
                'idempotency_key' => $idempotencyKey,
                'attempt_count' => $attempt,
                'related_lead_id' => $log->related_lead_id,
                'brand_id' => $log->brand_id,
                'is_critical' => (bool) $log->is_critical,
                'message_body' => $body,
                'subject' => $subject,
            ]
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'deduplicated' => false,
            'provider_response' => $result,
            'actor_id' => $actor->id,
        ];
    }
}
