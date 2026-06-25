<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use App\Services\JobNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(protected JobNotificationService $notifications) {}
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

        if ($request->boolean('send_sms') && $request->visibility === 'customer_visible' && $job->customer_id) {
            $recipient = User::find($job->customer_id);
            if ($recipient) {
                $this->notifications->manualMessageSms($recipient, $request->content, $job->id);
            }
        }

        return response()->json($message->load('sender:id,name,role'), 201);
    }
}
