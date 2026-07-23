<?php

namespace App\Services\PublicIntake;

use App\Contracts\ConversationalAiProviderInterface;
use App\Models\Brand;
use App\Models\IntakeSession;
use App\Services\Brands\BrandPromptTemplate;
use App\Services\LeadIntake\PublicIntakePipeline;
use Illuminate\Support\Str;

class PublicIntakeSessionService
{
    public function __construct(
        private ConversationalAiProviderInterface $conversationalAi,
        private PublicIntakePipeline $pipeline,
    ) {}

    public function start(Brand $brand): IntakeSession
    {
        $ttlHours = (int) config('public.intake_session_ttl_hours', 48);

        return IntakeSession::create([
            'brand_id' => $brand->id,
            'session_token' => Str::random(64),
            'conversation_state' => [
                'messages' => [],
                'collected' => [],
            ],
            'expires_at' => now()->addHours($ttlHours),
        ]);
    }

    public function findValidByToken(string $token, ?Brand $brand = null): ?IntakeSession
    {
        $query = IntakeSession::query()->where('session_token', $token);
        if ($brand) {
            $query->where('brand_id', $brand->id);
        }

        $session = $query->first();
        if (! $session || $session->isExpired()) {
            return null;
        }

        return $session;
    }

    /**
     * @return array{session: IntakeSession, reply: string, ready_to_submit: bool, collected: array<string, mixed>, provider: string}
     */
    public function message(IntakeSession $session, Brand $brand, string $content): array
    {
        if ($session->isConverted()) {
            throw new \RuntimeException('This intake session was already submitted.');
        }
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        $state = $session->conversation_state ?? [];
        $messages = is_array($state['messages'] ?? null) ? $state['messages'] : [];
        $collected = is_array($state['collected'] ?? null) ? $state['collected'] : [];

        $messages[] = [
            'role' => 'user',
            'content' => $content,
            'at' => now()->toIso8601String(),
        ];

        $promptVars = $brand->promptVariables();
        $systemPrompt = BrandPromptTemplate::render(
            (string) config('public.conversational_system_prompt'),
            $promptVars
        );

        $ai = $this->conversationalAi->respond($messages, $collected, [
            'brand_id' => $brand->id,
            'company_name' => $brand->company_name,
            'domain' => $brand->domain,
            'system_prompt' => $systemPrompt,
            'prompt_vars' => $promptVars,
            'service_categories' => $brand->serviceCatalog(),
        ]);

        $messages[] = [
            'role' => 'assistant',
            'content' => $ai['reply'],
            'at' => now()->toIso8601String(),
        ];

        $session->conversation_state = [
            'messages' => $messages,
            'collected' => $ai['collected'],
            'last_provider' => $ai['provider'],
            'ready_to_submit' => $ai['ready_to_submit'],
        ];
        $session->expires_at = now()->addHours((int) config('public.intake_session_ttl_hours', 48));
        $session->save();

        return [
            'session' => $session->fresh(),
            'reply' => $ai['reply'],
            'ready_to_submit' => $ai['ready_to_submit'],
            'collected' => $ai['collected'],
            'provider' => $ai['provider'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function submit(IntakeSession $session, Brand $brand, array $overrides = []): \App\Services\LeadIntake\LeadIntakeResult
    {
        if ($session->isConverted()) {
            throw new \RuntimeException('This intake session was already submitted.');
        }
        if ((int) $session->brand_id !== (int) $brand->id) {
            throw new \RuntimeException('Intake session does not belong to this brand.');
        }

        if ($overrides !== []) {
            $state = $session->conversation_state ?? [];
            $collected = is_array($state['collected'] ?? null) ? $state['collected'] : [];
            $session->conversation_state = array_merge($state, [
                'collected' => array_merge($collected, array_filter($overrides, fn ($v) => $v !== null && $v !== '')),
            ]);
            $session->save();
        }

        return $this->pipeline->submit($session->fresh());
    }
}
