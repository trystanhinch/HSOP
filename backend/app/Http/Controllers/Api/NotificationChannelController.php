<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\EmailService;
use App\Services\Messaging\NotificationChannelHealthService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A-19 — Channel health + test sends.
 */
class NotificationChannelController extends Controller
{
    public function __construct(
        protected NotificationChannelHealthService $health,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json($this->health->snapshot());
    }

    public function testSms(Request $request): JsonResponse
    {
        $actor = $request->user();
        $phone = SmsService::phoneForUser($actor) ?? $actor->phone;
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Add a phone number to your owner account to run a test SMS.']);
        }

        $result = app(SmsService::class)->send(
            $phone,
            'ServiceOP channel test: SMS provider check at '.now()->toDateTimeString(),
            'channel_health_test_sms',
            $actor->id,
            null,
            ['is_critical' => false]
        );

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'notification_channel',
            'object_id' => 0,
            'action_type' => 'channel_test_sms',
            'new_value' => ['result' => $result, 'health' => $this->health->smsHealth()],
            'created_at' => now(),
        ]);

        return response()->json([
            'provider_response' => $result,
            'health' => $this->health->smsHealth(),
        ]);
    }

    public function testEmail(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor->email) {
            throw ValidationException::withMessages(['email' => 'Add an email to your owner account to run a test email.']);
        }

        $result = app(EmailService::class)->send(
            $actor->email,
            'ServiceOP channel test email',
            'emails.notification',
            [
                'title' => 'Channel health test',
                'body' => 'This is a test email from notification channel health at '.now()->toDateTimeString(),
                'actionUrl' => null,
                'actionText' => null,
            ],
            'channel_health_test_email',
            $actor->id,
            null,
            ['is_critical' => false, 'message_body' => 'Channel health test']
        );

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'notification_channel',
            'object_id' => 0,
            'action_type' => 'channel_test_email',
            'new_value' => ['result' => $result, 'health' => $this->health->emailHealth()],
            'created_at' => now(),
        ]);

        return response()->json([
            'provider_response' => $result,
            'health' => $this->health->emailHealth(),
        ]);
    }
}
