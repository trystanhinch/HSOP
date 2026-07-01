<?php

namespace App\Services;

use App\Mail\HsopNotificationMail;
use App\Models\EmailLog;
use App\Models\Setting;
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
        if (! Setting::isGloballyEnabled('email')) {
            EmailLog::create([
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
            EmailLog::create([
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

            EmailLog::create([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'sent',
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage(), 'to' => $toEmail]);

            EmailLog::create([
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
        if (! Setting::isGloballyEnabled('email')) {
            EmailLog::create([
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
            EmailLog::create([
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

            EmailLog::create([
                'to_email' => $toEmail,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'status' => 'sent',
            ]);

            return ['success' => true];
        } catch (\Exception $e) {
            Log::error('Email send failed', ['error' => $e->getMessage(), 'to' => $toEmail]);

            EmailLog::create([
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
}
