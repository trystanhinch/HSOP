<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCommandSavedQuery;
use App\Models\AiCommandSession;
use App\Models\Setting;
use App\Services\CommandCenter\CommandCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandCenterController extends Controller
{
    public function __construct(private CommandCenterService $commands) {}

    public function sessions(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);

        $q = AiCommandSession::where('user_id', $request->user()->id)
            ->latest('last_message_at');

        if ($search = trim((string) $request->query('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('messages', fn ($m) => $m->where('content', 'like', '%'.$search.'%'));
            });
        }

        $sessions = $q->limit(50)->get(['id', 'title', 'last_message_at', 'created_at']);

        return response()->json([
            'data' => $sessions,
            'ai_kill_switch' => Setting::getBool('ai_kill_switch', false),
            'ai_simulation_mode' => Setting::getBool('ai_simulation_mode', false),
            'mode' => app(\App\Services\AiActionAuthorizer::class)->getModuleMode('command_center'),
        ]);
    }

    public function show(Request $request, AiCommandSession $aiCommandSession): JsonResponse
    {
        $this->assertSessionAccess($request, $aiCommandSession);

        return response()->json([
            'session' => $aiCommandSession,
            'messages' => $aiCommandSession->messages()->get(),
        ]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);
        $session = $this->commands->getOrCreateSession($request->user());

        return response()->json(['session' => $session], 201);
    }

    public function rename(Request $request, AiCommandSession $aiCommandSession): JsonResponse
    {
        $this->assertSessionAccess($request, $aiCommandSession);
        $data = $request->validate(['title' => 'required|string|max:120']);
        $aiCommandSession->update(['title' => $data['title']]);

        return response()->json(['session' => $aiCommandSession->fresh()]);
    }

    public function destroy(Request $request, AiCommandSession $aiCommandSession): JsonResponse
    {
        $this->assertSessionAccess($request, $aiCommandSession);
        $aiCommandSession->messages()->delete();
        $aiCommandSession->delete();

        return response()->json(['message' => 'Conversation deleted']);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);

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
            'citations' => $result['assistant_message']->meta['citations'] ?? [],
            'meta' => [
                'brand_scope' => $result['assistant_message']->meta['brand_scope'] ?? null,
                'data_refreshed_at' => $result['assistant_message']->meta['data_refreshed_at'] ?? null,
                'model' => $result['assistant_message']->meta['model'] ?? null,
                'response_kind' => $result['assistant_message']->meta['response_kind'] ?? null,
                'mode' => $result['assistant_message']->meta['mode'] ?? null,
                'kill_switch' => $result['assistant_message']->meta['kill_switch'] ?? false,
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);

        $data = $request->validate([
            'session_id' => 'required|integer',
            'pending_action' => 'required|array',
        ]);

        $session = AiCommandSession::where('user_id', $request->user()->id)
            ->findOrFail($data['session_id']);

        $result = $this->commands->confirmAction($request->user(), $session, $data['pending_action']);

        return response()->json($result);
    }

    public function savedQueries(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);
        $rows = AiCommandSavedQuery::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeSavedQuery(Request $request): JsonResponse
    {
        $this->assertOwnerOrPm($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'query_text' => 'required|string|max:4000',
        ]);

        $row = AiCommandSavedQuery::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'query_text' => $data['query_text'],
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function destroySavedQuery(Request $request, AiCommandSavedQuery $savedQuery): JsonResponse
    {
        $this->assertOwnerOrPm($request);
        if ((int) $savedQuery->user_id !== (int) $request->user()->id) {
            abort(403);
        }
        $savedQuery->delete();

        return response()->json(['message' => 'Saved query deleted']);
    }

    private function assertOwnerOrPm(Request $request): void
    {
        if (! in_array($request->user()?->role, ['owner', 'pm'], true)) {
            abort(403, 'AI Command Center requires owner or PM.');
        }
    }

    private function assertSessionAccess(Request $request, AiCommandSession $session): void
    {
        $this->assertOwnerOrPm($request);
        if ((int) $session->user_id !== (int) $request->user()->id) {
            abort(403);
        }
    }
}
