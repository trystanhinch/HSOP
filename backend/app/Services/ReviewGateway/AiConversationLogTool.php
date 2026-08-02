<?php

namespace App\Services\ReviewGateway;

use App\Models\AiConversationLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * GET ai-conversation-log — transcript for a conversation turn / session.
 * {conversationId} is AiConversationLog.id; related turns sharing intake_session_id or trace_id are included.
 */
class AiConversationLogTool
{
    public const TOOL = 'ai_conversation_log';

    public function __construct(private SensitiveDataGuard $guard) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(int $conversationId): array
    {
        $anchor = AiConversationLog::query()->find($conversationId);
        if (! $anchor) {
            throw (new ModelNotFoundException)->setModel(AiConversationLog::class, [$conversationId]);
        }

        $turnsQuery = AiConversationLog::query()->orderBy('turn_number')->orderBy('id');
        if ($anchor->intake_session_id) {
            $turnsQuery->where('intake_session_id', $anchor->intake_session_id);
        } elseif ($anchor->trace_id) {
            $turnsQuery->where('trace_id', $anchor->trace_id);
        } else {
            $turnsQuery->where('id', $anchor->id);
        }

        $turns = $turnsQuery->get()->map(fn (AiConversationLog $row) => [
            'id' => $row->id,
            'intake_session_id' => $row->intake_session_id,
            'lead_id' => $row->lead_id,
            'turn_number' => $row->turn_number,
            'role' => $row->role,
            'content' => $row->content,
            'content_preview' => $row->content_preview,
            'tool_calls' => $row->tool_calls,
            'tool_results' => $row->tool_results,
            'ai_provider' => $row->ai_provider,
            'ai_model' => $row->ai_model,
            'trace_id' => $row->trace_id,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ])->values()->all();

        $payload = [
            'tool' => self::TOOL,
            'tool_version' => config('review_gateway.tool_versions.ai_conversation_log', '1.0.0'),
            'anchor_id' => $anchor->id,
            'intake_session_id' => $anchor->intake_session_id,
            'conversation_trace_id' => $anchor->trace_id,
            'provider' => $anchor->ai_provider,
            'model' => $anchor->ai_model,
            'turns' => $turns,
        ];

        return $this->guard->scrub($payload);
    }
}
