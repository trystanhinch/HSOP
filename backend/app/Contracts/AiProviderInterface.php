<?php

namespace App\Contracts;

interface AiProviderInterface
{
    /**
     * @param  array<string, mixed>  $leadData
     * @return array{service_category: ?string, urgency: string, flags: array<string, bool>, confidence: array<string, float>}
     */
    public function classifyLead(array $leadData): array;

    /**
     * @param  array<string, mixed>  $leadData
     */
    public function summarizeLead(array $leadData): string;
}
