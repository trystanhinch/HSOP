<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCommandSession;
use App\Services\CommandCenter\CommandCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandCenterController extends Controller
{
    public function __construct(private CommandCenterService $commands) {}

    public function sessions(Request $request): JsonResponse
    {
        $this->assertOwner($request);

        $sessions = AiCommandSession::where('user_id', $request->user()->id)
            ->latest('last_message_at')
            ->limit(30)
            ->get(['id', 'title', 'last_message_at', 'created_at']);

        return response()->json(['data' => $sessions]);
    }

    public function show(Request $request, AiCommandSession $aiCommandSession): JsonResponse
    {
        $this->assertOwner($request);
        if ((int) $aiCommandSession->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        return response()->json([
            'session' => $aiCommandSession,
            'messages' => $aiCommandSession->messages()->get(),
        ]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $this->assertOwner($request);
        $session = $this->commands->getOrCreateSession($request->user());

        return response()->json(['session' => $session], 201);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->assertOwner($request);

        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'session_id' => 'nullable|integer',
        ]);

        $session = $this->commands->getOrCreateSession($request->user(), $data['session_id'] ?? null);
        $result = $this->commands->ask($request->user(), $session, $data['message']);

        return response()->json([
            'session' => $result['session'],
            'user_message' => $result['user_message'],
            'assistant_message' => $result['assistant_message'],
            'pending_action' => $result['assistant_message']->meta['pending_action'] ?? null,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->assertOwner($request);

        $data = $request->validate([
            'session_id' => 'required|integer',
            'pending_action' => 'required|array',
        ]);

        $session = AiCommandSession::where('user_id', $request->user()->id)
            ->findOrFail($data['session_id']);

        $result = $this->commands->confirmAction($request->user(), $session, $data['pending_action']);

        return response()->json($result);
    }

    private function assertOwner(Request $request): void
    {
        if ($request->user()?->role !== 'owner') {
            abort(403, 'AI Command Center is owner-only.');
        }
    }
}
