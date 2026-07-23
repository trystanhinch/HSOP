<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    | mock — deterministic keyword classification (local/tests)
    | openai — real OpenAI Chat Completions (requires OPENAI_API_KEY)
    */
    'provider' => env('AI_PROVIDER', 'mock'),

    'providers' => [
        'mock' => \App\Services\Ai\MockAiProvider::class,
        'openai' => \App\Services\Ai\OpenAiProvider::class,
    ],

    // Sibling conversational contract (public intake chat).
    'conversational_provider' => env('AI_CONVERSATIONAL_PROVIDER', 'mock'),
    'conversational_providers' => [
        'mock' => \App\Services\Ai\MockConversationalAiProvider::class,
        'openai' => \App\Services\Ai\OpenAiConversationalProvider::class,
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 20),
        // Default rates for gpt-4o-mini (USD per 1M tokens) — adjust via env if model changes
        'cost_per_1m_input_tokens' => (float) env('OPENAI_COST_PER_1M_INPUT', 0.15),
        'cost_per_1m_output_tokens' => (float) env('OPENAI_COST_PER_1M_OUTPUT', 0.60),
    ],
];
