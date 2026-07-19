<?php

namespace Tests\Unit;

use App\Services\LeadIntake\KeywordCategoryClassifier;
use App\Services\LeadIntake\LeadEmailParser;
use Tests\TestCase;

class LeadEmailParserTest extends TestCase
{
    private LeadEmailParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new LeadEmailParser(new KeywordCategoryClassifier);
    }

    public function test_parses_clean_fixture(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/clean_lead.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('Sample', $parsed->firstName);
        $this->assertSame('Customer', $parsed->lastName);
        $this->assertSame('customer@example.com', $parsed->email);
        $this->assertStringContainsString('604', $parsed->phone ?? '');
        $this->assertSame('Residential drywall / painting', $parsed->serviceRequested);
        $this->assertTrue($parsed->marketingConsent);
        $this->assertFalse($parsed->needsManualReview);
    }

    public function test_messy_fixture_flags_manual_review(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/messy_partial.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertTrue($parsed->needsManualReview);
        $this->assertSame('jrivera.partial@example.com', $parsed->email);
        $this->assertNotNull($parsed->phone);
    }

    public function test_ambiguous_service_fixture_parses_contact_fields(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/ambiguous_service.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('Alex', $parsed->firstName);
        $this->assertSame('Morgan', $parsed->lastName);
        $this->assertStringContainsString('repair', strtolower($parsed->serviceRequested ?? ''));
    }

    public function test_parses_coquitlam_drywall_form_lead(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/coquitlam_drywall_andrew.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('form', $parsed->emailFormat);
        $this->assertSame('Andrew', $parsed->firstName);
        $this->assertSame('Lloyd', $parsed->lastName);
        $this->assertSame('Coquitlam Drywall', $parsed->sourceLabel);
        $this->assertStringContainsString('popcorn', strtolower($parsed->projectDescription ?? ''));
        $this->assertFalse($parsed->needsManualReview);
    }

    public function test_parses_vancouver_insulation_form_lead(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/vancouver_insulation_jeffrey.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('Jeffrey', $parsed->firstName);
        $this->assertSame('Blondin', $parsed->lastName);
        $this->assertSame('Vancouver Insulation', $parsed->sourceLabel);
        $this->assertStringContainsString('spray foam', strtolower($parsed->projectDescription ?? ''));
        $this->assertTrue($parsed->marketingConsent); // declined prohibition = marketing OK
    }

    public function test_parses_mission_drywall_form_lead(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/mission_drywall_mike.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('Mike', $parsed->firstName);
        $this->assertSame('Creelman', $parsed->lastName);
        $this->assertSame('Mission Drywall', $parsed->sourceLabel);
        $this->assertFalse($parsed->marketingConsent); // accepted prohibition
    }

    public function test_voicemail_always_needs_manual_review_and_keeps_recording(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/voicemail_insulation_vancouver.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('voicemail', $parsed->emailFormat);
        $this->assertTrue($parsed->needsManualReview);
        $this->assertSame('Insulation Vancouver', $parsed->sourceLabel);
        $this->assertStringContainsString('604', $parsed->phone ?? '');
        $this->assertNotNull($parsed->recordingUrl);
        $this->assertStringContainsString('twilio.com', $parsed->recordingUrl);
        $this->assertNull($parsed->firstName);
        $this->assertNull($parsed->email);
        $this->assertNull($parsed->projectDescription);
    }

    public function test_voicemail_drywall_richmond(): void
    {
        $raw = file_get_contents(base_path('tests/fixtures/lead_emails/voicemail_drywall_richmond.txt'));
        $parsed = $this->parser->parse($raw);

        $this->assertSame('voicemail', $parsed->emailFormat);
        $this->assertTrue($parsed->needsManualReview);
        $this->assertSame('Drywall Richmond', $parsed->sourceLabel);
        $this->assertStringContainsString('778', $parsed->phone ?? '');
        $this->assertNotNull($parsed->recordingUrl);
    }
}
