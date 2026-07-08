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
            'module_modes' => $modes,
            'available_modes' => config('ai_actions.modes', []),
            'modules' => $modules,
            'action_registry' => $this->registry->all()->values(),
            'recent_action_logs' => AiActionLog::with('actor:id,name,role')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ai_kill_switch' => 'nullable|boolean',
            'module_modes' => 'nullable|array',
            'module_modes.*' => 'in:suggestion,assisted,autopilot',
        ]);

        if (array_key_exists('ai_kill_switch', $data)) {
            $this->authorizer->assertOwnerOnly($request->user(), 'change_ai_kill_switch');
            Setting::setBool('ai_kill_switch', (bool) $data['ai_kill_switch']);
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

    public function actionLogs(Request $request): JsonResponse
    {
        $logs = AiActionLog::with('actor:id,name,role')
            ->when($request->action_taken, fn ($q) => $q->where('action_taken', $request->action_taken))
            ->latest()
            ->paginate(50);

        return response()->json($logs);
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
