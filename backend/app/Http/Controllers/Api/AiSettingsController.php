<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiActionLog;
use App\Models\Setting;
use App\Services\AiActionAuthorizer;
use App\Services\AiActionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function __construct(
        protected AiActionAuthorizer $authorizer,
        protected AiActionRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        $modules = config('ai_actions.modules', []);
        $modes = [];

        foreach ($modules as $module) {
            $modes[$module] = Setting::get("ai_mode_{$module}", config('ai_actions.default_mode', 'suggestion'));
        }

        return response()->json([
            'ai_kill_switch' => Setting::getBool('ai_kill_switch', false),
            'ai_simulation_mode' => Setting::getBool('ai_simulation_mode', false),
            'ai_daily_action_limit' => (int) Setting::get('ai_daily_action_limit', config('ai_actions.limits.daily_action_limit', 200)),
            'ai_daily_cost_usd_limit' => (float) Setting::get('ai_daily_cost_usd_limit', config('ai_actions.limits.daily_cost_usd_limit', 25)),
            'ai_conversation_retention_days' => \App\Services\Learning\AiConversationLogger::retentionDays(),
            'ai_conversation_retention_default' => \App\Services\Learning\AiConversationLogger::DEFAULT_RETENTION_DAYS,
            'ai_conversation_last_purge_at' => Setting::get('ai_conversation_last_purge_at'),
            'ai_conversation_last_purge_count' => Setting::get('ai_conversation_last_purge_count'),
            'module_modes' => $modes,
            'available_modes' => config('ai_actions.modes', []),
            'mode_definitions' => config('ai_actions.mode_definitions', []),
            'modules' => $modules,
            'action_registry' => $this->registry->all()->values(),
            'recent_action_logs' => AiActionLog::with('actor:id,name,role')
                ->rootIncidents()
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (AiActionLog $log) => $log->toPublicArray(false)),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ai_kill_switch' => 'nullable|boolean',
            'ai_simulation_mode' => 'nullable|boolean',
            'ai_daily_action_limit' => 'nullable|integer|min:1|max:100000',
            'ai_daily_cost_usd_limit' => 'nullable|numeric|min:0|max:100000',
            'ai_conversation_retention_days' => 'nullable|integer|min:1|max:3650',
            'module_modes' => 'nullable|array',
            'module_modes.*' => 'in:suggestion,assisted,autopilot',
        ]);

        if (array_key_exists('ai_kill_switch', $data)) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_kill_switch');
            Setting::setBool('ai_kill_switch', (bool) $data['ai_kill_switch']);
        }

        if (array_key_exists('ai_simulation_mode', $data)) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_operating_mode');
            Setting::setBool('ai_simulation_mode', (bool) $data['ai_simulation_mode']);
        }

        if (array_key_exists('ai_daily_action_limit', $data) && $data['ai_daily_action_limit'] !== null) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_operating_mode');
            Setting::set('ai_daily_action_limit', (string) (int) $data['ai_daily_action_limit']);
        }

        if (array_key_exists('ai_daily_cost_usd_limit', $data) && $data['ai_daily_cost_usd_limit'] !== null) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_operating_mode');
            Setting::set('ai_daily_cost_usd_limit', (string) $data['ai_daily_cost_usd_limit']);
        }

        if (array_key_exists('ai_conversation_retention_days', $data) && $data['ai_conversation_retention_days'] !== null) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_kill_switch');
            Setting::set(
                \App\Services\Learning\AiConversationLogger::RETENTION_SETTING,
                (string) (int) $data['ai_conversation_retention_days']
            );
        }

        if (! empty($data['module_modes'])) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_operating_mode');
            foreach ($data['module_modes'] as $module => $mode) {
                if (in_array($module, config('ai_actions.modules', []), true)) {
                    Setting::set("ai_mode_{$module}", $mode);
                }
            }
        }

        return response()->json(['message' => 'AI settings updated']);
    }

    public function conversationLogs(Request $request): JsonResponse
    {
        $reveal = $request->boolean('reveal_content');
        if ($reveal && $request->user()?->role !== 'owner') {
            abort(403, 'Only owners may reveal full AI conversation content.');
        }

        $logs = \App\Models\AiConversationLog::query()
            ->when($request->lead_id, fn ($q) => $q->where('lead_id', (int) $request->lead_id))
            ->when($request->intake_session_id, fn ($q) => $q->where('intake_session_id', $request->intake_session_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('intake_session_id')
            ->orderBy('turn_number')
            ->paginate((int) ($request->per_page ?? 50));

        $logs->getCollection()->transform(function ($row) use ($reveal) {
            $preview = $row->content_preview
                ?: (\Illuminate\Support\Str::limit((string) $row->content, 80) ?: null);
            $full = $row->content;
            $row->content_preview = $preview;
            $row->content_redacted = ! $reveal;
            $row->content = $reveal ? $full : null;
            $row->tool_calls = $reveal ? $row->tool_calls : null;
            $row->tool_results = $reveal ? $row->tool_results : null;

            return $row;
        });

        return response()->json($logs);
    }

    public function revealConversationContent(Request $request, int $id): JsonResponse
    {
        if ($request->user()?->role !== 'owner') {
            abort(403, 'Only owners may reveal full AI conversation content.');
        }

        $row = \App\Models\AiConversationLog::findOrFail($id);

        return response()->json([
            'id' => $row->id,
            'content' => $row->content,
            'tool_calls' => $row->tool_calls,
            'tool_results' => $row->tool_results,
            'revealed' => true,
        ]);
    }

    public function actionLogs(Request $request): JsonResponse
    {
        $reveal = $request->boolean('reveal_content') && $request->user()?->role === 'owner';

        $logs = AiActionLog::with(['actor:id,name,role', 'children'])
            ->rootIncidents()
            ->when($request->trigger_event, fn ($q) => $q->where('trigger_event', $request->trigger_event))
            ->when($request->action_taken, fn ($q) => $q->where('action_taken', $request->action_taken))
            ->when($request->trace_id, fn ($q) => $q->where('trace_id', $request->trace_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->errors_only === 'true', fn ($q) => $q->whereNotNull('error')->where('error', '!=', ''))
            ->latest()
            ->paginate((int) ($request->per_page ?? 50));

        $logs->getCollection()->transform(function (AiActionLog $log) use ($reveal) {
            $arr = $log->toPublicArray($reveal);
            $arr['children_count'] = $log->children->count();
            $arr['grouped_retries'] = (int) $log->retry_count + $log->children->count();

            return $arr;
        });

        return response()->json($logs);
    }

    public function actionLogFilters(): JsonResponse
    {
        $events = AiActionLog::query()->distinct()->orderBy('trigger_event')->pluck('trigger_event');
        $actions = AiActionLog::query()->distinct()->orderBy('action_taken')->pluck('action_taken');

        return response()->json([
            'trigger_events' => $events,
            'action_taken' => $actions,
        ]);
    }

    public function storeTestLog(Request $request): JsonResponse
    {
        $aiUser = \App\Models\User::aiSuperAdmin();
        if (! $aiUser) {
            return response()->json(['message' => 'AI Super Admin user not seeded.'], 422);
        }

        $data = $request->validate([
            'trigger_event' => 'required|string|max:100',
            'action_taken' => 'nullable|string|max:100',
            'decision' => 'nullable|string',
        ]);

        $log = AiActionLog::create([
            'trigger_event' => $data['trigger_event'],
            'actor_id' => $aiUser->id,
            'data_viewed' => ['note' => 'Phase 1 manual test entry'],
            'decision' => $data['decision'] ?? 'Test log entry for Phase 1 verification.',
            'action_taken' => $data['action_taken'] ?? 'test_action',
            'required_human_approval' => true,
        ]);

        return response()->json($log->load('actor:id,name,role'), 201);
    }
}
