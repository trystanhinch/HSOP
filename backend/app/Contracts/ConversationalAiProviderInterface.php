<?php

namespace App\Contracts;

/**
 * Conversational AI for public intake (and future chat surfaces).
 *
 * Sibling to AiProviderInterface (one-shot classify/summarize). Brand context
 * (company name, services, tone) is passed in — never hardcoded in implementations.
 */
interface ConversationalAiProviderInterface
{
    /**
     * Non-streaming turn (tests, JSON clients, fallbacks).
     *
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $collected
     * @param  array{
     *   brand_id?: int|null,
     *   company_name?: string,
     *   domain?: string,
     *   system_prompt?: string,
     *   prompt_vars?: array<string, string>,
     *   service_categories?: list<array{key: string, label: string, keywords: list<string>}>
     * }  $context
     * @return array{
     *   reply: string,
     *   collected: array<string, mixed>,
     *   ready_to_submit: bool,
     *   provider: string,
     *   usage?: array<string, mixed>|null,
     *   error?: string|null,
     *   needs_manual_review?: bool
     * }
     */
    public function respond(array $history, array $collected = [], array $context = []): array;

    /**
     * Streaming turn — yields event arrays for SSE:
     *   delta: {event:delta, text}
     *   collected: {event:collected, collected}
     *   done: {event:done, reply, collected, ready_to_submit, provider, usage?, needs_manual_review?}
     *   error: {event:error, message, collected?, ready_to_submit?, provider?}
     *
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $context
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamRespond(array $history, array $collected = [], array $context = []): \Generator;
}
