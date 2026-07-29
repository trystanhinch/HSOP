<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Services\BrandResolver;
use App\Services\Messaging\MessageTemplateService;
use App\Services\SmsService;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageTemplateController extends Controller
{
    public function __construct(
        protected MessageTemplateService $templates,
    ) {}

    public function index(): JsonResponse
    {
        $rows = MessageTemplate::query()->orderBy('event_key')->get();
        $brandName = Brand::where('status', 'active')->value('company_name')
            ?? app(BrandResolver::class)->fallback();

        $payload = $rows->map(function (MessageTemplate $tpl) use ($brandName) {
            $sample = $this->templates->sampleVars($tpl->event_key);
            $preview = $this->templates->preview($tpl->body ?? '', $sample, $tpl->channel ?? 'sms');
            $lastVersion = MessageTemplateVersion::query()
                ->where('message_template_id', $tpl->id)
                ->with('changedByUser:id,name,email')
                ->latest('version')
                ->first();

            return array_merge($tpl->toArray(), [
                'sample_preview' => $preview,
                'sample_vars' => $sample,
                'preview_brand_name' => $brandName,
                'last_changed_by' => $lastVersion?->changedByUser?->only(['id', 'name', 'email']),
                'last_changed_at' => $lastVersion?->created_at,
                'version' => $lastVersion?->version,
            ]);
        });

        return response()->json($payload);
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

        if (array_key_exists('body', $data)) {
            $this->templates->assertResolvable($messageTemplate, $data['body']);
        }

        $previous = $messageTemplate->only(['label', 'body', 'channel', 'is_active', 'variables']);
        $messageTemplate->update($data);
        $fresh = $messageTemplate->fresh();
        $version = $this->templates->saveVersion($fresh, $request->user(), 'template_updated');

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'message_template',
            'object_id' => $fresh->id,
            'action_type' => 'message_template_updated',
            'previous_value' => $previous,
            'new_value' => array_merge($fresh->only(['label', 'body', 'channel', 'is_active', 'variables']), [
                'version' => $version->version,
                'effective_at' => now()->toIso8601String(),
            ]),
            'created_at' => now(),
        ]);

        return response()->json($fresh);
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

        $tpl = new MessageTemplate($data);
        $this->templates->assertResolvable($tpl, $data['body']);

        $tpl = MessageTemplate::create([
            'event_key' => $data['event_key'],
            'label' => $data['label'],
            'body' => $data['body'],
            'channel' => $data['channel'] ?? 'sms',
            'variables' => $data['variables'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->templates->saveVersion($tpl, $request->user(), 'template_created');

        return response()->json($tpl, 201);
    }

    public function preview(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:5000',
            'brand_id' => 'nullable|integer|exists:brands,id',
        ]);

        $brand = ! empty($data['brand_id']) ? Brand::find($data['brand_id']) : null;
        $vars = $this->templates->sampleVars($messageTemplate->event_key, $brand);
        $body = $data['body'] ?? $messageTemplate->body;
        $preview = $this->templates->preview($body, $vars, $messageTemplate->channel ?? 'sms');

        return response()->json([
            'event_key' => $messageTemplate->event_key,
            'brand_name' => $vars['company_name'],
            'sample_vars' => $vars,
            'preview' => $preview,
        ]);
    }

    public function testSend(Request $request, MessageTemplate $messageTemplate): JsonResponse
    {
        $data = $request->validate([
            'channel' => 'nullable|in:sms,email',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'body' => 'nullable|string|max:5000',
        ]);

        $actor = $request->user();
        $brand = ! empty($data['brand_id']) ? Brand::find($data['brand_id']) : null;
        $vars = $this->templates->sampleVars($messageTemplate->event_key, $brand);
        $body = $data['body'] ?? $messageTemplate->body;
        $this->templates->assertResolvable($messageTemplate, $body, $vars);
        $preview = $this->templates->preview($body, $vars, $messageTemplate->channel ?? 'sms');
        $rendered = $preview['rendered'];

        // Ensure BrandResolver brand appears (not hardcoded ServiceOP) when company_name is in body.
        $channel = $data['channel'] ?? (($messageTemplate->channel === 'email') ? 'email' : 'sms');

        if ($channel === 'sms') {
            $phone = SmsService::phoneForUser($actor) ?? $actor->phone;
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Your user account has no phone number for test SMS.']);
            }
            $result = app(SmsService::class)->send(
                $phone,
                '[TEST] '.$rendered,
                'template_test_'.$messageTemplate->event_key,
                $actor->id,
                null,
                ['is_critical' => false, 'brand_id' => $brand?->id]
            );
        } else {
            if (! $actor->email) {
                throw ValidationException::withMessages(['email' => 'Your user account has no email for test send.']);
            }
            $result = app(EmailService::class)->send(
                $actor->email,
                '[TEST] '.$messageTemplate->label,
                'emails.notification',
                [
                    'title' => '[TEST] '.$messageTemplate->label,
                    'body' => $rendered,
                    'actionUrl' => null,
                    'actionText' => null,
                ],
                'template_test_'.$messageTemplate->event_key,
                $actor->id,
                null,
                ['is_critical' => false, 'brand_id' => $brand?->id, 'message_body' => $rendered]
            );
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'message_template',
            'object_id' => $messageTemplate->id,
            'action_type' => 'message_template_test_send',
            'new_value' => [
                'channel' => $channel,
                'brand_name' => $vars['company_name'],
                'result' => $result,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Test send attempted to your contact.',
            'channel' => $channel,
            'brand_name' => $vars['company_name'],
            'preview' => $preview,
            'provider_response' => $result,
        ]);
    }

    public function versions(MessageTemplate $messageTemplate): JsonResponse
    {
        $versions = MessageTemplateVersion::query()
            ->where('message_template_id', $messageTemplate->id)
            ->with('changedByUser:id,name,email')
            ->orderByDesc('version')
            ->limit(50)
            ->get();

        return response()->json(['versions' => $versions]);
    }

    public function restore(Request $request, MessageTemplate $messageTemplate, MessageTemplateVersion $version): JsonResponse
    {
        $restored = $this->templates->restore($messageTemplate, $version, $request->user());

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_role' => $request->user()->role,
            'object_type' => 'message_template',
            'object_id' => $messageTemplate->id,
            'action_type' => 'message_template_restored',
            'new_value' => [
                'restored_version' => $version->version,
                'effective_at' => now()->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return response()->json($restored);
    }
}
