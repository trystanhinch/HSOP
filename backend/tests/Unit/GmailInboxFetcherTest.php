<?php

namespace Tests\Unit;

use App\Services\Gmail\GmailInboxFetcher;
use App\Services\Gmail\GmailOAuthService;
use App\Services\LeadIntake\LeadIntakePipeline;
use ReflectionMethod;
use Tests\TestCase;

class GmailInboxFetcherTest extends TestCase
{
    public function test_extracts_plain_text_body_from_multipart_payload(): void
    {
        $fetcher = new GmailInboxFetcher(
            $this->createMock(GmailOAuthService::class),
            $this->createMock(LeadIntakePipeline::class),
        );

        $method = new ReflectionMethod(GmailInboxFetcher::class, 'extractBodyText');
        $method->setAccessible(true);

        $plain = base64_encode("Name: Andrew\nService: Drywall");
        $plain = rtrim(strtr($plain, '+/', '-_'), '=');

        $text = $method->invoke($fetcher, [
            'parts' => [
                [
                    'mimeType' => 'text/plain',
                    'body' => ['data' => $plain],
                ],
                [
                    'mimeType' => 'text/html',
                    'body' => ['data' => rtrim(strtr(base64_encode('<b>html</b>'), '+/', '-_'), '=')],
                ],
            ],
        ]);

        $this->assertStringContainsString('Name: Andrew', $text);
        $this->assertStringContainsString('Service: Drywall', $text);
    }
}
