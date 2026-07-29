<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use App\Mail\NewMessageMail;
use App\Services\EmailService;
use App\Services\Messaging\AssignmentMessageService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Job-scoped messaging.
 * CT-02: contractor channel filter + lead_id carry-forward threads.
 * PM-04: delivery_status / recipient_label + customer cannot access internals.
 */
class MessageController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email,
        protected AssignmentMessageService $assignmentMessages,
    ) {}

    public function index(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();
        $job = Job::findOrFail($jobId);

        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // CT-02: contractors only see contractor↔PM (+ customer-visible) channels — never pm_internal.
        if ($user->role === 'contractor') {
            $messages = $this->assignmentMessages->threadForJob($job, $user);
            Message::where(function ($q) use ($job) {
                $q->where('job_id', $job->id);
                if ($job->lead_id) {
                    $q->orWhere('lead_id', $job->lead_id);
                }
            })
                ->whereIn('channel', AssignmentMessageService::CONTRACTOR_VISIBLE_CHANNELS)
                ->where('sender_id', '!=', $user->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            return response()->json($messages);
        }

        $query = Message::query()
            ->where(function ($q) use ($job) {
                $q->where('job_id', $job->id);
                if ($job->lead_id && $this->assignmentMessages->leadSupportsMessages()) {
                    $q->orWhere('lead_id', $job->lead_id);
                }
            })
            ->with('sender:id,name,role')
            ->oldest();

        // PM-04: customers never see internal notes (ignore visibility query tampering).
        if ($user->role === 'customer') {
            $query->where('visibility', 'customer_visible');
        } elseif ($request->visibility) {
            $query->where('visibility', $request->visibility);
        }

        $messages = $query->get()->unique('id')->values();

        Message::where('job_id', $job->id)
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->when($user->role === 'customer', fn ($q) => $q->where('visibility', 'customer_visible'))
            ->update(['is_read' => true]);

        return response()->json($messages);
    }

    public function store(Request $request, string $jobId): JsonResponse
    {
        $user = $request->user();
        $job = Job::findOrFail($jobId);

        if ($user->role === 'contractor' && $job->contractor_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'customer' && $job->customer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        if ($user->role === 'pm' && $job->pm_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'content' => 'required|string|max:5000',
            'visibility' => 'required|in:customer_visible,internal',
            'channel' => 'nullable|string',
            'send_sms' => 'nullable|boolean',
        ]);

        // PM-04: customers cannot post internal notes
        if ($user->role === 'customer' && $request->visibility === 'internal') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // CT-02: contractor↔PM assignment channel (never pm_internal owner notes).
        if ($user->role === 'contractor') {
            $channel = 'contractor_to_pm';
            $visibility = 'internal';
        } else {
            $channel = match (true) {
                $user->role === 'customer' => 'customer_to_pm',
                $request->visibility === 'customer_visible' => 'pm_to_customer',
                default => 'pm_internal',
            };
            $visibility = $request->visibility;
        }

        $job->loadMissing(['customer', 'lead']);
        $customer = User::find($job->customer_id);
        $isInternal = $visibility === 'internal';

        $recipientLabel = $isInternal
            ? ($user->role === 'contractor' ? 'pm' : 'internal')
            : ($customer?->name ?? $job->lead?->contact_name ?? 'Customer');

        $receiverId = match (true) {
            $user->role === 'contractor' => $job->pm_id,
            $user->role === 'customer' => $job->pm_id,
            ! $isInternal => $job->customer_id,
            default => null,
        };

        $deliveryStatus = 'recorded';
        $deliveryMeta = ['in_app' => true];

        $message = Message::create([
            'job_id' => $job->id,
            'lead_id' => $job->lead_id,
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'sender_role' => $user->role,
            'content' => $request->content,
            'visibility' => $visibility,
            'channel' => $channel,
            'recipient_label' => $recipientLabel,
            'delivery_status' => $deliveryStatus,
        ]);

        if (! $isInternal && $user->role !== 'customer') {
            $portalUrl = SmsMessageTemplates::customerPortalUrlForJob($job);
            $customerPhone = SmsService::phoneForUser($customer) ?? $job->lead?->phone;
            $customerName = $customer?->name ?? $job->lead?->contact_name ?? 'there';
            $customerEmail = $customer?->email ?? $job->lead?->email;

            $smsOk = false;
            $emailOk = false;

            if ($customerPhone) {
                try {
                    $this->sms->send(
                        $customerPhone,
                        "Hi {$customerName}, you have a new message about your project at {$job->address}. View it here: {$portalUrl}",
                        'new_message_customer',
                        $customer?->id,
                        $job->id
                    );
                    $smsOk = true;
                } catch (\Throwable) {
                    $smsOk = false;
                }
            }

            if ($customerEmail) {
                try {
                    $this->email->sendMailable(
                        $customerEmail,
                        new NewMessageMail($job, $message, $portalUrl),
                        'new_message_customer',
                        $customer?->id,
                        $job->id
                    );
                    $emailOk = true;
                } catch (\Throwable) {
                    $emailOk = false;
                }
            }

            $deliveryMeta = [
                'in_app' => true,
                'sms' => $smsOk,
                'email' => $emailOk,
                'delivery_channel' => match (true) {
                    $smsOk && $emailOk => 'sms+email',
                    $smsOk => 'sms',
                    $emailOk => 'email',
                    default => 'in_app',
                },
            ];
            $deliveryStatus = ($smsOk || $emailOk) ? 'notified' : 'in_app_only';
            $message->update([
                'delivery_status' => $deliveryStatus,
            ]);
        }

        if (in_array($user->role, ['customer', 'contractor'], true)) {
            $pm = User::find($job->pm_id);
            if ($pm && SmsService::phoneForUser($pm)) {
                $this->sms->sendToUser(
                    $pm,
                    "New message from {$user->name} on job at {$job->address}.",
                    'new_message_pm',
                    $job->id
                );
            }
        }

        AuditLog::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'object_type' => 'message',
            'object_id' => $message->id,
            'action_type' => 'message_sent',
            'new_value' => [
                'channel' => $channel,
                'visibility' => $visibility,
                'sender_id' => $user->id,
                'sender_role' => $user->role,
                'recipient' => $recipientLabel,
                'receiver_id' => $receiverId,
                'delivery_status' => $deliveryStatus,
                'delivery' => $deliveryMeta,
                'job_id' => $job->id,
                'lead_id' => $job->lead_id,
            ],
            'created_at' => now(),
        ]);

        return response()->json($message->fresh()->load('sender:id,name,role'), 201);
    }
}
