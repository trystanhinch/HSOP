<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiActionLog;
use App\Models\Job;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Contracts\AiProviderInterface;
use App\Services\AiActionAuthorizer;
use App\Services\Workflow\WorkflowSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkflowAssistController extends Controller
{
    public function __construct(
        private AiProviderInterface $ai,
        private AiActionAuthorizer $authorizer,
        private WorkflowSettings $workflowSettings,
    ) {}

    public function thresholds(): JsonResponse
    {
        return response()->json($this->workflowSettings->all());
    }

    public function updateThresholds(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pm_contact_lead_hours' => 'nullable|numeric|min:0.5|max:168',
            'pm_contact_escalation_hours' => 'nullable|numeric|min:0.5|max:168',
            'contractor_pricing_deadline_hours' => 'nullable|numeric|min:1|max:336',
            'quote_follow_up_hours' => 'nullable|numeric|min:1|max:336',
            'job_missing_update_days' => 'nullable|numeric|min:1|max:60',
        ]);

        return response()->json($this->workflowSettings->updateMany($data));
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

        return response()->json([
            'ai_drafted' => true,
            'draft' => $text ?: MessageTemplate::render(
                'pm_intro_customer',
                ['customer_name' => $lead->contact_name ?? 'there', 'pm_name' => auth()->user()->name],
                'Hi {{customer_name}}, this is {{pm_name}} from ServiceOP following up on your project inquiry. When is a good time to chat?'
            ),
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
