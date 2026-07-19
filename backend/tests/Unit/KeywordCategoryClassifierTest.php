<?php

namespace Tests\Unit;

use App\Services\Ai\MockAiProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\LeadIntake\KeywordCategoryClassifier;
use Tests\TestCase;

class KeywordCategoryClassifierTest extends TestCase
{
    private KeywordCategoryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new KeywordCategoryClassifier;
    }

    public function test_classifies_city_drywall_variants(): void
    {
        foreach (['Coquitlam Drywall', 'Drywall Richmond', 'Mission Drywall Client From Bil'] as $subject) {
            $result = $this->classifier->classify(['subject' => $subject]);
            $this->assertSame('drywall_paint', $result['service_category'], $subject);
        }
    }

    public function test_classifies_insulation_variants(): void
    {
        foreach (['Vancouver Insulation', 'Insulation Vancouver', 'Insulation Ethos'] as $subject) {
            $result = $this->classifier->classify(['subject' => $subject]);
            $this->assertSame('insulation', $result['service_category'], $subject);
        }
    }

    public function test_ambiguous_when_no_keywords(): void
    {
        $result = $this->classifier->classify([
            'subject' => 'General inquiry',
            'service_requested' => 'Not sure',
            'project_description' => 'Please call me back',
        ]);

        $this->assertNull($result['service_category']);
        $this->assertTrue($result['flags']['ambiguous_service']);
    }

    public function test_openai_provider_class_is_loadable(): void
    {
        $this->assertTrue(class_exists(OpenAiProvider::class));
        $this->assertTrue(is_subclass_of(OpenAiProvider::class, \App\Contracts\AiProviderInterface::class));
        $this->assertSame(MockAiProvider::class, config('ai.providers.mock'));
        $this->assertSame(OpenAiProvider::class, config('ai.providers.openai'));
    }
}
