<?php

namespace App\Services;

use App\Mail\HsopNotificationMail;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Services\TestData\TestDataGuard;
use App\Services\Customers\CommunicationGuard;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    public function send(
        ?string $toEmail,
        string $subject,
        string $view,
        array $viewData,
        string $triggerEvent,
        $userId = null,
        $jobId = null
    ): array {
        $guard = app(TestDataGuard::class)->checkOutbound(
            userId: $userId ? (int) $userId : null,
            jobId: $jobId ? (int) $jobId : null,
            email: $toEmail,
        );
        if ($guard['blocked']) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'blocked_test_data',
                'error_message' => 'Blocked: '.$guard['reason'],
            ]);

            return ['success' => false, 'reason' => 'test_data', 'detail' => $guard['reason']];
        }

        $comm = app(CommunicationGuard::class)->checkEmail(
            userId: $userId ? (int) $userId : null,
            email: $toEmail,
        );
        if ($comm['blocked']) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'blocked_do_not_contact',
                'error_message' => 'Blocked: '.$comm['reason'],
            ]);

            return ['success' => false, 'reason' => 'do_not_contact', 'detail' => $comm['reason']];
        }

        if (! Setting::isGloballyEnabled('email')) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => 'Email is disabled globally in settings',
            ]);

            return ['success' => false, 'reason' => 'email_disabled'];
        }

        if (! $toEmail) {
            $this->writeLog([
                'to_email' => 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => 'No email address on file',
            ]);

            return ['success' => false, 'reason' => 'no_email'];
        }

        try {
            Mail::to($toEmail)->send(new HsopNotificationMail($subject, $view, $viewData));

            $this->writeLog([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'sent',
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage(), 'to' => $toEmail]);

            $this->writeLog([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function sendMailable(
        ?string $toEmail,
        Mailable $mailable,
        string $triggerEvent,
        $userId = null,
        $jobId = null
    ): array {
        $guard = app(TestDataGuard::class)->checkOutbound(
            userId: $userId ? (int) $userId : null,
            jobId: $jobId ? (int) $jobId : null,
            email: $toEmail,
        );
        if ($guard['blocked']) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'blocked_test_data',
                'error_message' => 'Blocked: '.$guard['reason'],
            ]);

            return ['success' => false, 'reason' => 'test_data', 'detail' => $guard['reason']];
        }

        $comm = app(CommunicationGuard::class)->checkEmail(
            userId: $userId ? (int) $userId : null,
            email: $toEmail,
        );
        if ($comm['blocked']) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'blocked_do_not_contact',
                'error_message' => 'Blocked: '.$comm['reason'],
            ]);

            return ['success' => false, 'reason' => 'do_not_contact', 'detail' => $comm['reason']];
        }

        if (! Setting::isGloballyEnabled('email')) {
            $this->writeLog([
                'to_email' => $toEmail ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => 'Email is disabled globally in settings',
            ]);

            return ['success' => false, 'reason' => 'email_disabled'];
        }

        if (! $toEmail) {
            $this->writeLog([
                'to_email' => 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => 'No email address on file',
            ]);

            return ['success' => false, 'reason' => 'no_email'];
        }

        try {
            Mail::to($toEmail)->send($mailable);

            $this->writeLog([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'sent',
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage(), 'to' => $toEmail]);

            $this->writeLog([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
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
}
