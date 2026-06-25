<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\User;
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

    public function send(?string $toPhone, string $message, string $triggerEvent, $userId = null, $jobId = null): array
    {
        if (! Setting::isGloballyEnabled('sms')) {
            SmsLog::create([
                'to_phone' => $toPhone ?: 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'message_body' => $message,
                'status' => 'disabled',
                'error_message' => 'SMS is disabled globally in settings',
            ]);

            return ['success' => false, 'reason' => 'sms_disabled'];
        }

        if (! $toPhone) {
            SmsLog::create([
                'to_phone' => 'MISSING',
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'message_body' => $message,
                'status' => 'failed',
                'error_message' => 'No phone number on file',
            ]);

            return ['success' => false, 'reason' => 'no_phone'];
        }

        if (! $this->enabled || ! $this->client) {
            SmsLog::create([
                'to_phone' => $toPhone,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'message_body' => $message,
                'status' => 'disabled',
                'error_message' => 'SMS disabled or Twilio not configured',
            ]);

            return ['success' => false, 'reason' => 'sms_disabled'];
        }

        if ($userId) {
            $user = User::find($userId);
            if ($user && $user->sms_enabled === false) {
                SmsLog::create([
                    'to_phone' => $toPhone,
                    'user_id' => $userId,
                    'trigger_event' => $triggerEvent,
                    'related_job_id' => $jobId,
                    'message_body' => $message,
                    'status' => 'disabled',
                    'error_message' => 'SMS disabled for this user',
                ]);

                return ['success' => false, 'reason' => 'user_disabled'];
            }
        }

        try {
            $result = $this->client->messages->create($toPhone, [
                'from' => $this->from,
                'body' => $message,
            ]);

            SmsLog::create([
                'to_phone' => $toPhone,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'message_body' => $message,
                'status' => 'sent',
                'provider_message_id' => $result->sid,
            ]);

            return ['success' => true, 'sid' => $result->sid];
        } catch (\Exception $e) {
            Log::error('SMS send failed', ['error' => $e->getMessage(), 'to' => $toPhone]);

            SmsLog::create([
                'to_phone' => $toPhone,
                'user_id' => $userId,
                'trigger_event' => $triggerEvent,
                'related_job_id' => $jobId,
                'message_body' => $message,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'send_failed', 'error' => $e->getMessage()];
        }
    }

    public function sendToUser(?User $user, string $message, string $triggerEvent, ?int $jobId = null): array
    {
        return $this->send(
            self::phoneForUser($user),
            $message,
            $triggerEvent,
            $user?->id,
            $jobId
        );
    }
}
