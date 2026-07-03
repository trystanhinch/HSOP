<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use App\Mail\NewMessageMail;
use App\Services\EmailService;
use App\Services\SmsMessageTemplates;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(
        protected SmsService $sms,
        protected EmailService $email
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

        $query = Message::where('job_id', $job->id)
            ->with('sender:id,name,role')
            ->oldest();

        if ($request->visibility) {
            $query->where('visibility', $request->visibility);
        } elseif ($user->role === 'customer') {
            $query->where('visibility', 'customer_visible');
        }

        $messages = $query->get();

        // Mark messages as read for the current user (not sent by them)
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

        $channel = match (true) {
            $user->role === 'customer' => 'customer_to_pm',
            $user->role === 'contractor' => 'contractor_to_pm',
            $request->visibility === 'customer_visible' => 'pm_to_customer',
            default => 'pm_internal',
        };

        $message = Message::create([
            'job_id' => $job->id,
            'sender_id' => $user->id,
            'sender_role' => $user->role,
            'content' => $request->content,
            'visibility' => $request->visibility,
            'channel' => $channel,
        ]);

        $job->loadMissing(['customer', 'lead']);

        if ($request->visibility === 'customer_visible' && $user->role !== 'customer') {
            $customer = User::find($job->customer_id);
            $portalUrl = SmsMessageTemplates::customerPortalUrlForJob($job);
            $customerPhone = SmsService::phoneForUser($customer) ?? $job->lead?->phone;
            $customerName = $customer?->name ?? $job->lead?->contact_name ?? 'there';
            $customerEmail = $customer?->email ?? $job->lead?->email;

            if ($customerPhone) {
                $this->sms->send(
                    $customerPhone,
                    "Hi {$customerName}, you have a new message about your project at {$job->address}. View it here: {$portalUrl}",
                    'new_message_customer',
                    $customer?->id,
                    $job->id
                );
            }

            if ($customerEmail) {
                $this->email->sendMailable(
                    $customerEmail,
                    new NewMessageMail($job, $message, $portalUrl),
                    'new_message_customer',
                    $customer?->id,
                    $job->id
                );
            }
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
            'object_type' => 'job',
            'object_id' => $job->id,
            'action_type' => 'message_sent',
        ]);

        return response()->json($message->load('sender:id,name,role'), 201);
    }
}
