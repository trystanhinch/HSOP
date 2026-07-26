<?php

namespace App\Services\Ai;

use App\Contracts\ConversationalAiProviderInterface;
use App\Models\AiActionLog;
use App\Models\User;
use App\Services\AiActionAuthorizer;
use App\Services\Brands\BrandPromptTemplate;

/**
 * Phase 1 mock — brand-aware echo + field extraction from brand service catalog.
 * Real streaming OpenAI arrives in Phase 2 using the same context contract.
 */
class MockConversationalAiProvider implements ConversationalAiProviderInterface
{
    public function __construct(private AiActionAuthorizer $authorizer) {}

    public function respond(array $history, array $collected = [], array $context = []): array
    {
        $final = [
            'reply' => '',
            'collected' => $collected,
            'ready_to_submit' => $this->ready($collected),
            'provider' => 'mock',
            'usage' => null,
        ];
        foreach ($this->streamRespond($history, $collected, $context) as $event) {
            if (($event['event'] ?? '') === 'done') {
                $final = [
                    'reply' => (string) ($event['reply'] ?? ''),
                    'collected' => $event['collected'] ?? $collected,
                    'ready_to_submit' => (bool) ($event['ready_to_submit'] ?? false),
                    'provider' => (string) ($event['provider'] ?? 'mock'),
                    'usage' => $event['usage'] ?? null,
                ];
            }
        }

        return $final;
    }

    public function streamRespond(array $history, array $collected = [], array $context = []): \Generator
    {
        $lastUser = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'user') {
                $lastUser = trim((string) ($history[$i]['content'] ?? ''));
                break;
            }
        }

        $company = (string) ($context['company_name'] ?? 'our team');
        $services = is_array($context['service_categories'] ?? null) ? $context['service_categories'] : [];
        $serviceLabels = array_values(array_filter(array_map(
            static fn ($c) => is_array($c) ? (string) ($c['label'] ?? '') : '',
            $services
        )));
        $servicesPhrase = $serviceLabels !== [] ? implode(', ', $serviceLabels) : 'our services';

        $systemPrompt = (string) ($context['system_prompt'] ?? '');
        if ($systemPrompt === '' && ! empty($context['prompt_vars'])) {
            $systemPrompt = BrandPromptTemplate::render(
                (string) config('public.conversational_system_prompt'),
                $context['prompt_vars']
            );
        }

        if (! $this->authorizer->isAiEnabled()) {
            $reply = "Thanks — the {$company} assistant is temporarily paused. Please share your name, phone, and project details and a team member will follow up.";
            $this->log($history, $collected, $reply, 'mock_kill_switch', $context);
            yield ['event' => 'delta', 'text' => $reply];
            yield [
                'event' => 'done',
                'reply' => $reply,
                'collected' => $collected,
                'ready_to_submit' => $this->ready($collected),
                'provider' => 'mock_kill_switch',
                'usage' => null,
                'needs_manual_review' => true,
            ];

            return;
        }

        $updated = $this->extractFields($lastUser, $collected, $services);
        $missing = $this->missingFields($updated);
        $scopeAsked = (int) ($updated['scope_questions_asked'] ?? 0);
        $hasProject = ! empty($updated['project_description']) || ! empty($updated['service_category']);

        if ($missing === []) {
            if (empty($updated['scope_confirmed'])) {
                $desc = (string) ($updated['project_description'] ?? 'your project');
                $reply = 'Just to confirm — you need help with: '.mb_strimwidth($desc, 0, 120, '…')
                    .'. Does that sound right?';
            } elseif (! array_key_exists('wants_price', $updated)) {
                $reply = 'Would you like a rough price range, or skip straight to next steps?';
            } elseif (! empty($updated['wants_price']) && empty($updated['price_note_sent'])) {
                $reply = 'Thanks — a team member from '.$company.' will follow up with pricing. Want to pick a site-visit time?';
                $updated['price_note_sent'] = true;
            } elseif (! array_key_exists('wants_scheduling', $updated)) {
                $reply = 'Would you like to hold a site-visit time, or just submit the request?';
            } else {
                $reply = "Thanks — I have what I need for {$company}. Want me to submit this request?";
            }
        } elseif ($lastUser === '') {
            $reply = "Hi — I can help start your {$company} request. What are you seeing on site?";
        } elseif ($hasProject && $scopeAsked < 3) {
            // Scope-deepening before name/phone (mirrors live OpenAI pacing).
            $scopeQuestions = [
                'Is the damaged area soft or getting worse, or mostly surface staining?',
                'Roughly how large is the affected area, and how easy is it to reach?',
                'Any finish details we should know (patch only vs blend/paint a larger area)? A photo helps if you want to upload one.',
            ];
            $reply = $scopeQuestions[$scopeAsked];
            $updated['scope_questions_asked'] = $scopeAsked + 1;
        } else {
            $next = $missing[0];
            $reply = match ($next) {
                'service_category' => "Which {$company} service fits best: {$servicesPhrase}?",
                'contact_name' => "Thanks — what first name should {$company} use?",
                'phone' => 'What\'s the best phone number to reach you?',
                'email' => 'And an email address (optional)?',
                'project_description' => 'In a sentence or two, what needs fixing?',
                'address' => 'What city or area is the job in?',
                default => 'Could you share a bit more so we can help?',
            };
        }

        $this->log($history, $updated, $reply, 'mock', $context, $systemPrompt);

        // Simulate streaming chunks for SSE clients / tests
        $chunks = str_split($reply, 40);
        foreach ($chunks as $chunk) {
            yield ['event' => 'delta', 'text' => $chunk];
        }
        yield ['event' => 'collected', 'collected' => $updated];
        yield [
            'event' => 'done',
            'reply' => $reply,
            'collected' => $updated,
            'ready_to_submit' => $this->ready($updated),
            'provider' => 'mock',
            'usage' => null,
            'needs_manual_review' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  list<array{key: string, label: string, keywords: list<string>}>  $services
     * @return array<string, mixed>
     */
    private function extractFields(string $text, array $collected, array $services): array
    {
        $out = $collected;
        $lower = strtolower($text);

        if (! isset($out['service_category']) && $services !== []) {
            $bestKey = null;
            $bestScore = 0;
            foreach ($services as $service) {
                $score = 0;
                foreach ($service['keywords'] as $kw) {
                    if ($kw !== '' && str_contains($lower, $kw)) {
                        $score += strlen($kw);
                    }
                }
                $label = strtolower((string) ($service['label'] ?? ''));
                if ($label !== '' && str_contains($lower, $label)) {
                    $score += strlen($label);
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestKey = $service['key'];
                }
            }
            if ($bestKey && $bestScore > 0) {
                $out['service_category'] = $bestKey;
            }
        }

        if (preg_match('/\b(\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/', $text, $m)) {
            $out['phone'] = $m[0];
        }

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            $out['email'] = $m[0];
        }

        if (preg_match('/\b(?:i\'?m|my name is|this is)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $text, $m)) {
            $out['contact_name'] = trim($m[1]);
        } elseif (! isset($out['contact_name']) && preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?$/', trim($text))) {
            $out['contact_name'] = trim($text);
        }

        if (! isset($out['project_description']) && strlen($text) > 40
            && (str_contains($lower, 'need') || str_contains($lower, 'want') || str_contains($lower, 'project') || str_contains($lower, 'room'))) {
            $out['project_description'] = $text;
        }

        // Generic place/area capture — not a fixed city list
        if (! isset($out['address']) && preg_match('/\b(?:in|at|near)\s+([A-Za-z][A-Za-z\s-]{1,40})\b/u', $text, $m)) {
            $out['address'] = trim($m[1]);
        }

        if (! isset($out['size_sqft']) && preg_match('/(\d+(?:\.\d+)?)\s*(?:sq\.?\s*ft|sqft|sf)\b/i', $text, $m)) {
            $out['size_sqft'] = (float) $m[1];
        }

        if (preg_match('/\b(yes|yeah|yep|correct|that\'?s right|sounds right|confirm)\b/i', $text)
            && ! empty($out['project_description'])
            && empty($out['scope_confirmed'])) {
            $out['scope_confirmed'] = true;
        }

        if (preg_match('/\b(price|estimate|ballpark|how much|cost)\b/i', $text)) {
            $out['wants_price'] = true;
        }
        if (preg_match('/\b(no thanks|skip (the )?price|don\'?t (need|want) (a )?price)\b/i', $text)) {
            $out['wants_price'] = false;
        }

        if (preg_match('/\b(schedule|book|visit|appointment|come out)\b/i', $text)) {
            $out['wants_scheduling'] = true;
        }

        if (preg_match('/\b(speak (with|to)|talk to|call me|human|person|someone)\b/i', $text)) {
            $out['wants_human_handoff'] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @return list<string>
     */
    private function missingFields(array $collected): array
    {
        $required = ['service_category', 'contact_name', 'phone', 'project_description'];
        $missing = [];
        foreach ($required as $key) {
            if (empty($collected[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $collected
     */
    private function ready(array $collected): bool
    {
        return $this->missingFields($collected) === [];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $context
     */
    private function log(
        array $history,
        array $collected,
        string $reply,
        string $provider,
        array $context,
        string $systemPrompt = '',
    ): void {
        AiActionLog::create([
            'trigger_event' => 'public_intake_chat',
            'actor_id' => User::aiSuperAdmin()?->id,
            'data_viewed' => [
                'history_turns' => count($history),
                'collected_keys' => array_keys($collected),
                'provider' => $provider,
                'brand_id' => $context['brand_id'] ?? null,
                'company_name' => $context['company_name'] ?? null,
                'system_prompt_preview' => $systemPrompt !== '' ? mb_strimwidth($systemPrompt, 0, 180, '…') : null,
            ],
            'decision' => 'mock_reply',
            'action_taken' => 'conversational_respond',
            'message_sent' => mb_strimwidth($reply, 0, 200, '…'),
            'recipient' => null,
            'status_before' => null,
            'status_after' => null,
            'rule_applied' => 'public_intake_mock',
            'required_human_approval' => false,
        ]);
    }
}
