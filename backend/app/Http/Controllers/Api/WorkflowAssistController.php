<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Contracts\AiProviderInterface;
use App\Models\AiActionLog;
use App\Models\BusinessHoursProfile;
use App\Models\Job;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowThresholdVersion;
use App\Services\AiActionAuthorizer;
use App\Services\Workflow\BusinessHoursCalculator;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class WorkflowAssistController extends Controller
{
    public function __construct(
        private AiProviderInterface $ai,
        private AiActionAuthorizer $authorizer,
        private WorkflowSettings $workflowSettings,
        private BusinessHoursCalculator $businessHours,
    ) {}

    public function thresholds(): JsonResponse
    {
        $out = $this->workflowSettings->all();
        $out['profiles'] = BusinessHoursProfile::query()->orderByDesc('is_default')->get();
        $out['versions'] = WorkflowThresholdVersion::query()->orderByDesc('id')->limit(20)->get();

        return response()->json($out);
    }

    public function updateThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pm_contact_lead_hours' => 'nullable|numeric|min:0.5|max:168',
            'pm_contact_escalation_hours' => 'nullable|numeric|min:0.5|max:168',
            'contractor_pricing_deadline_hours' => 'nullable|numeric|min:1|max:336',
            'quote_follow_up_hours' => 'nullable|numeric|min:1|max:336',
            'job_missing_update_days' => 'nullable|numeric|min:1|max:60',
            'clock_mode' => ['nullable', Rule::in(['wall', 'business'])],
            'business_hours_profile_id' => 'nullable|integer|exists:business_hours_profiles,id',
            'notes' => 'nullable|string|max:500',
        ]);

        foreach (['pm_contact_lead_hours', 'pm_contact_escalation_hours', 'contractor_pricing_deadline_hours', 'quote_follow_up_hours', 'job_missing_update_days'] as $key) {
            if (array_key_exists($key, $data) && (float) $data[$key] <= 0) {
                return response()->json(['message' => "{$key} must be positive."], 422);
            }
        }

        if (isset($data['clock_mode'])) {
            Setting::set('workflow_clock_mode', $data['clock_mode']);
        }
        if (array_key_exists('business_hours_profile_id', $data) && $data['business_hours_profile_id']) {
            Setting::set('workflow_business_hours_profile_id', (string) $data['business_hours_profile_id']);
            BusinessHoursProfile::query()->update(['is_default' => false]);
            BusinessHoursProfile::query()->whereKey($data['business_hours_profile_id'])->update(['is_default' => true]);
        }

        $thresholdPayload = collect($data)->only([
            'pm_contact_lead_hours',
            'pm_contact_escalation_hours',
            'contractor_pricing_deadline_hours',
            'quote_follow_up_hours',
            'job_missing_update_days',
        ])->filter(fn ($v) => $v !== null)->all();

        $updated = $this->workflowSettings->updateMany($thresholdPayload);

        $profile = $this->businessHours->resolveProfile();
        $preview = $this->businessHours->previewTimeline(
            (float) ($updated['pm_contact_lead_hours'] ?? 4),
            (float) ($updated['pm_contact_escalation_hours'] ?? 4),
            $profile,
            $updated['clock_mode'] ?? null,
        );

        WorkflowThresholdVersion::create([
            'actor_id' => $request->user()?->id,
            'thresholds' => $updated,
            'preview_timeline' => $preview,
            'clock_mode' => $updated['clock_mode'] ?? 'business',
            'business_hours_profile_id' => $profile->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $updated['preview_timeline'] = $preview;
        $updated['profiles'] = BusinessHoursProfile::query()->orderByDesc('is_default')->get();
        $updated['versions'] = WorkflowThresholdVersion::query()->orderByDesc('id')->limit(20)->get();

        return response()->json($updated);
    }

    /**
     * A-15 — Preview exact reminder/escalation timeline before saving.
     */
    public function previewThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pm_contact_lead_hours' => 'required|numeric|min:0.5|max:168',
            'pm_contact_escalation_hours' => 'required|numeric|min:0.5|max:168',
            'clock_mode' => ['nullable', Rule::in(['wall', 'business'])],
            'brand_id' => 'nullable|integer',
            'from' => 'nullable|date',
        ]);

        if ((float) $data['pm_contact_lead_hours'] <= 0 || (float) $data['pm_contact_escalation_hours'] <= 0) {
            return response()->json(['message' => 'Thresholds must be positive.'], 422);
        }

        $profile = $this->businessHours->resolveProfile(
            isset($data['brand_id']) ? (int) $data['brand_id'] : null
        );

        $timeline = $this->businessHours->previewTimeline(
            (float) $data['pm_contact_lead_hours'],
            (float) $data['pm_contact_escalation_hours'],
            $profile,
            $data['clock_mode'] ?? null,
            isset($data['from']) ? \Carbon\Carbon::parse($data['from']) : null,
        );

        return response()->json([
            'preview_timeline' => $timeline,
            'timezone' => $profile->timezone,
            'clock_mode' => $data['clock_mode'] ?? $this->businessHours->clockMode(),
            'notified' => [
                ['when' => 'reminder', 'who' => 'assigned_pm', 'channel' => 'sms+in_app'],
                ['when' => 'escalation', 'who' => 'owner', 'channel' => 'sms+in_app'],
            ],
        ]);
    }

    public function upsertBusinessHours(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:business_hours_profiles,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'name' => 'required|string|max:120',
            'timezone' => 'required|string|max:64',
            'weekly_hours' => 'required|array',
            'holidays' => 'nullable|array',
            'holidays.*' => 'date',
            'is_default' => 'nullable|boolean',
        ]);

        if (! empty($data['is_default'])) {
            BusinessHoursProfile::query()->update(['is_default' => false]);
        }

        $profile = isset($data['id'])
            ? BusinessHoursProfile::query()->findOrFail($data['id'])
            : new BusinessHoursProfile;

        $profile->fill([
            'brand_id' => $data['brand_id'] ?? null,
            'name' => $data['name'],
            'timezone' => $data['timezone'],
            'weekly_hours' => $data['weekly_hours'],
            'holidays' => $data['holidays'] ?? [],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);
        $profile->save();

        if ($profile->is_default) {
            Setting::set('workflow_business_hours_profile_id', (string) $profile->id);
        }

        return response()->json($profile);
    }

    public function callPrep(Lead $lead): JsonResponse
    {
        $this->authorizePmOrOwner($lead);

        $payload = [
            'contact_name' => $lead->contact_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'address' => $lead->address,
            'service_category' => $lead->service_category,
            'project_description' => $lead->project_description,
            'source' => $lead->source,
            'notes' => $lead->notes,
            'subject' => $lead->parse_metadata['subject'] ?? null,
            'email_format' => $lead->parse_metadata['email_format'] ?? null,
        ];

        $summary = $this->ai->summarizeLead($payload);
        $draft = $this->openaiAssist(
            'call_prep',
            'You help a project manager prepare for a customer call. Return JSON with keys: scope_summary, location_notes, urgency_signals (array), suggested_questions (array), possible_exclusions (array).',
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );

        $this->logAssist('call_prep', $lead->id, 'lead');

        return response()->json([
            'ai_drafted' => true,
            'short_summary' => $summary,
            'call_prep' => $draft ?: [
                'scope_summary' => $summary,
                'location_notes' => $lead->address ?: 'Confirm address on the call.',
                'urgency_signals' => [],
                'suggested_questions' => [
                    'What is the ideal timing for this work?',
                    'Are there access or parking constraints?',
                    'Any rooms or surfaces that should be excluded?',
                ],
                'possible_exclusions' => [],
            ],
        ]);
    }

    public function draftMessage(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizePmOrOwner($lead);
        $data = $request->validate([
            'intent' => 'nullable|string|max:200',
        ]);

        $intent = $data['intent'] ?? 'Introduce yourself as the PM and confirm next steps for their project inquiry.';
        $text = $this->openaiText(
            'draft_customer_message',
            'Draft a short professional SMS/email to a home-service customer. Plain text only. No markdown.',
            "Intent: {$intent}\nCustomer: {$lead->contact_name}\nService: {$lead->service_category}\nDescription: {$lead->project_description}"
        );

        $this->logAssist('draft_message', $lead->id, 'lead');

        $brand = app(\App\Services\BrandResolver::class)->forLead($lead);
        $fallbackDraft = MessageTemplate::render(
            'pm_intro_customer',
            [
                'company_name' => $brand,
                'customer_name' => $lead->contact_name ?? 'there',
                'pm_name' => auth()->user()->name,
            ],
            'Hi {{customer_name}}, this is {{pm_name}} from '.$brand.' following up on your project inquiry. When is a good time to chat?'
        );

        return response()->json([
            'ai_drafted' => true,
            'draft' => $text ?: ($fallbackDraft ?? ''),
            'note' => 'AI-drafted — review and edit before sending. Not auto-sent.',
        ]);
    }

    public function quotePrep(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizePmOrOwner($lead);

        if (! $lead->contractor_price) {
            return response()->json(['message' => 'No contractor price submitted yet.'], 422);
        }

        $contractor = (float) $lead->contractor_price;
        $divisor = (float) (Setting::get('markup_divisor', '0.80') ?: 0.80);
        $customerTotal = $divisor > 0 ? round($contractor / $divisor, 2) : $contractor;
        $markup = round($customerTotal - $contractor, 2);
        $gstRate = (float) (Setting::get('gst_rate', '5') ?: 5);
        $gst = round($customerTotal * ($gstRate / 100), 2);

        $wording = $this->openaiText(
            'quote_prep',
            'Write professional customer-facing scope wording for a quote (2-4 sentences). Plain text. Do not invent prices.',
            "Service: {$lead->service_category}\nDescription: {$lead->project_description}\nContractor net: {$contractor}"
        );

        $this->logAssist('quote_prep', $lead->id, 'lead');

        return response()->json([
            'ai_drafted' => true,
            'scope_wording' => $wording ?: ('Work as discussed: '.($lead->project_description ?: $lead->service_category)),
            'pricing' => [
                'contractor_price' => $contractor,
                'customer_subtotal' => $customerTotal,
                'markup' => $markup,
                'gst_rate' => $gstRate,
                'gst' => $gst,
                'customer_total' => round($customerTotal + $gst, 2),
                'split' => [
                    'contractor_pct' => Setting::get('split_contractor_pct', '80'),
                    'pm_pct' => Setting::get('split_pm_pct', '10'),
                    'company_pct' => Setting::get('split_company_pct', '10'),
                ],
            ],
            'note' => 'AI-drafted scope + calculated pricing — PM must review before sending.',
        ]);
    }

    private function authorizePmOrOwner(Lead $lead): void
    {
        $user = auth()->user();
        if ($user->role === 'owner') {
            return;
        }
        if ($user->role === 'pm' && (int) $lead->assigned_pm_id === (int) $user->id) {
            return;
        }
        abort(403);
    }

    private function openaiAssist(string $action, string $system, string $user): ?array
    {
        $text = $this->openaiText($action, $system.' Respond with JSON only.', $user, true);
        if (! $text) {
            return null;
        }
        $parsed = json_decode($text, true);

        return is_array($parsed) ? $parsed : null;
    }

    private function openaiText(string $action, string $system, string $user, bool $json = false): ?string
    {
        if (! $this->authorizer->isAiEnabled()) {
            return null;
        }

        $apiKey = config('ai.openai.api_key');
        if (! $apiKey || config('ai.provider') !== 'openai') {
            // Still try if key present even when provider mock — prefer real for PM assist
            if (! $apiKey) {
                return null;
            }
        }

        try {
            $payload = [
                'model' => config('ai.openai.model', 'gpt-4o-mini'),
                'temperature' => 0.3,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ];
            if ($json) {
                $payload['response_format'] = ['type' => 'json_object'];
            }

            $response = Http::withToken($apiKey)
                ->timeout((int) config('ai.openai.timeout', 20))
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (! $response->successful()) {
                Log::warning('PM AI assist failed', ['action' => $action, 'status' => $response->status()]);

                return null;
            }

            return trim((string) ($response->json('choices.0.message.content') ?? '')) ?: null;
        } catch (Throwable $e) {
            Log::warning('PM AI assist exception', ['action' => $action, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function logAssist(string $action, int $subjectId, string $subjectType): void
    {
        try {
            AiActionLog::create([
                'trigger_event' => 'pm_ai_assist',
                'actor_id' => auth()->id() ?? User::aiSuperAdmin()?->id,
                'data_viewed' => ['action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
                'decision' => 'draft_generated',
                'action_taken' => $action,
                'message_sent' => null,
                'recipient' => null,
                'status_before' => null,
                'status_after' => null,
                'rule_applied' => 'pm_assist_'.$action,
                'required_human_approval' => true,
                'error' => null,
            ]);
        } catch (Throwable) {
            //
        }
    }
}
