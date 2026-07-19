<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MessageTemplate::query()->orderBy('event_key')->get());
    }

    public function update(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        $data = $request->validate([
            'label' => 'sometimes|string|max:120',
            'body' => 'sometimes|string|max:5000',
            'channel' => 'sometimes|in:sms,email,both',
            'is_active' => 'sometimes|boolean',
            'variables' => 'sometimes|array',
        ]);

        $messageTemplate->update($data);

        return response()->json($messageTemplate->fresh());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_key' => 'required|string|max:80|unique:message_templates,event_key',
            'label' => 'required|string|max:120',
            'body' => 'required|string|max:5000',
            'channel' => 'nullable|in:sms,email,both',
            'variables' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $tpl = MessageTemplate::create([
            'event_key' => $data['event_key'],
            'label' => $data['label'],
            'body' => $data['body'],
            'channel' => $data['channel'] ?? 'sms',
            'variables' => $data['variables'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json($tpl, 201);
    }
}
