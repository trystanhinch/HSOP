<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PmContractorMessageController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'pm') {
            $contractors = User::where('role', 'contractor')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']);

            $conversations = $contractors->map(function (User $contractor) use ($user) {
                $messages = $this->threadQuery($user->id, $contractor->id);
                $last = (clone $messages)->latest()->first();
                $unread = (clone $messages)->where('receiver_id', $user->id)->where('is_read', false)->count();

                return [
                    'user_id' => $contractor->id,
                    'name' => $contractor->name,
                    'email' => $contractor->email,
                    'role' => $contractor->role,
                    'last_message' => $last?->content,
                    'last_message_at' => $last?->created_at,
                    'unread_count' => $unread,
                ];
            });

            return response()->json($conversations->values());
        }

        if ($user->role === 'contractor') {
            $pms = User::where('role', 'pm')->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']);

            $conversations = $pms->map(function (User $pm) use ($user) {
                $messages = $this->threadQuery($user->id, $pm->id);
                $last = (clone $messages)->latest()->first();
                $unread = (clone $messages)->where('receiver_id', $user->id)->where('is_read', false)->count();

                return [
                    'user_id' => $pm->id,
                    'name' => $pm->name,
                    'email' => $pm->email,
                    'role' => $pm->role,
                    'last_message' => $last?->content,
                    'last_message_at' => $last?->created_at,
                    'unread_count' => $unread,
                ];
            });

            return response()->json($conversations->values());
        }

        return response()->json(['message' => 'Unauthorized'], 403);
    }

    public function thread(Request $request, string $userId): JsonResponse
    {
        $user = $request->user();
        $other = User::findOrFail($userId);

        if (! $this->canMessage($user, $other)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $messages = $this->threadQuery($user->id, $other->id)
            ->with('sender:id,name,role')
            ->oldest()
            ->get();

        Message::where('channel', 'pm_contractor_direct')
            ->where('receiver_id', $user->id)
            ->where('sender_id', $other->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json($messages);
    }

    public function store(Request $request, string $userId): JsonResponse
    {
        $user = $request->user();
        $receiver = User::findOrFail($userId);

        if (! $this->canMessage($user, $receiver)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['content' => 'required|string|max:5000']);

        $message = Message::create([
            'job_id' => null,
            'channel' => 'pm_contractor_direct',
            'sender_id' => $user->id,
            'receiver_id' => $receiver->id,
            'sender_role' => $user->role,
            'content' => $request->content,
            'visibility' => 'internal',
            'is_read' => false,
        ]);

        return response()->json($message->load('sender:id,name,role'), 201);
    }

    protected function canMessage(User $sender, User $receiver): bool
    {
        if ($sender->role === 'pm' && $receiver->role === 'contractor') {
            return true;
        }

        if ($sender->role === 'contractor' && $receiver->role === 'pm') {
            return true;
        }

        return false;
    }

    protected function threadQuery(int $userA, int $userB)
    {
        return Message::where('channel', 'pm_contractor_direct')
            ->where(function ($q) use ($userA, $userB) {
                $q->where(function ($q) use ($userA, $userB) {
                    $q->where('sender_id', $userA)->where('receiver_id', $userB);
                })->orWhere(function ($q) use ($userA, $userB) {
                    $q->where('sender_id', $userB)->where('receiver_id', $userA);
                });
            });
    }
}
