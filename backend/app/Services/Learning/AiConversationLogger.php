<?php

namespace App\Services\Learning;

use App\Models\AiConversationLog;
use App\Models\IntakeSession;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Full AI conversation capture for Learning Centre.
 * Writes are deferred with afterResponse() so streaming chat is not blocked.
 * Capture-only — no recommendations.
 */
class AiConversationLogger
{
    public const RETENTION_SETTING = 'ai_conversation_retention_days';

    public const DEFAULT_RETENTION_DAYS = 365;

    /**
     * @param  array{
     *   role: string,
     *   content?: string|null,
     *   tool_calls?: array<mixed>|null,
     *   tool_results?: array<mixed>|null,
     *   ai_provider?: string|null,
     *   ai_model?: string|null,
     *   turn_number?: int|null,
     * }  $turn
     */
    public function queueTurn(IntakeSession $session, array $turn): void
    {
        $payload = [
            'intake_session_id' => $session->id,
            'lead_id' => $session->converted_lead_id,
            'turn_number' => $turn['turn_number'] ?? null,
            'role' => (string) ($turn['role'] ?? 'user'),
            'content' => $turn['content'] ?? null,
            'tool_calls' => $turn['tool_calls'] ?? null,
            'tool_results' => $turn['tool_results'] ?? null,
            'ai_provider' => $turn['ai_provider'] ?? null,
            'ai_model' => $turn['ai_model'] ?? null,
            'created_at' => now(),
        ];

        // Defer past the HTTP/SSE response so logging never stalls the visitor chat path.
        // Tests write sync so assertions see rows without relying on terminate callbacks.
        if (app()->runningUnitTests()) {
            $this->writeTurn($payload);

            return;
        }

        try {
            dispatch(function () use ($payload) {
                $this->writeTurn($payload);
            })->afterResponse();
        } catch (\Throwable $e) {
            $this->writeTurn($payload);
            Log::warning('AiConversationLogger fell back to sync write', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function writeTurn(array $payload): void
    {
        try {
            $turnNumber = $payload['turn_number'] ?? null;
            if ($turnNumber === null) {
                $turnNumber = ((int) AiConversationLog::query()
                    ->where('intake_session_id', $payload['intake_session_id'])
                    ->max('turn_number')) + 1;
            }

            AiConversationLog::create([
                'intake_session_id' => $payload['intake_session_id'],
                'lead_id' => $payload['lead_id'] ?? null,
                'turn_number' => (int) $turnNumber,
                'role' => $payload['role'],
                'content' => $payload['content'] ?? null,
                'tool_calls' => $payload['tool_calls'] ?? null,
                'tool_results' => $payload['tool_results'] ?? null,
                'ai_provider' => $payload['ai_provider'] ?? null,
                'ai_model' => $payload['ai_model'] ?? null,
                'created_at' => $payload['created_at'] ?? now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write ai_conversation_logs row', ['error' => $e->getMessage()]);
        }
    }

    public function linkSessionToLead(IntakeSession $session, int $leadId): int
    {
        return AiConversationLog::query()
            ->where('intake_session_id', $session->id)
            ->where(function ($q) use ($leadId) {
                $q->whereNull('lead_id')->orWhere('lead_id', '!=', $leadId);
            })
            ->update(['lead_id' => $leadId]);
    }

    public static function retentionDays(): int
    {
        $raw = Setting::get(self::RETENTION_SETTING);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $raw;

        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public function purgeExpired(): int
    {
        $days = self::retentionDays();
        $cutoff = now()->subDays($days);

        return AiConversationLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();
    }
}
