<?php

namespace Tests\Unit;

use App\Services\Gmail\GmailOAuthService;
use Tests\TestCase;

class GmailOAuthServiceTest extends TestCase
{
    public function test_authorization_url_includes_client_scope_and_redirect(): void
    {
        config([
            'gmail.client_id' => 'test-client-id.apps.googleusercontent.com',
            'gmail.client_secret' => 'test-secret-value',
            'gmail.redirect_uri' => 'http://127.0.0.1:8000/oauth/gmail/callback',
            'gmail.mailbox' => 'leads@serviceop.ca',
            'gmail.scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
        ]);

        $url = app(GmailOAuthService::class)->authorizationUrl(1);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        $this->assertSame('test-client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://127.0.0.1:8000/oauth/gmail/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly', $query['scope']);
        $this->assertSame('leads@serviceop.ca', $query['login_hint']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringNotContainsString('test-secret-value', $url);
    }

    public function test_connection_status_never_exposes_client_secret(): void
    {
        config([
            'gmail.client_id' => 'test-client-id.apps.googleusercontent.com',
            'gmail.client_secret' => 'super-secret-do-not-leak',
            'gmail.redirect_uri' => 'http://127.0.0.1:8000/oauth/gmail/callback',
        ]);

        if (! \Illuminate\Support\Facades\Schema::hasTable('gmail_oauth_tokens')) {
            \Illuminate\Support\Facades\Schema::create('gmail_oauth_tokens', function ($table) {
                $table->id();
                $table->string('mailbox_email')->unique();
                $table->text('access_token_encrypted')->nullable();
                $table->text('refresh_token_encrypted')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->string('scope')->nullable();
                $table->unsignedBigInteger('connected_by')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamp('last_fetched_at')->nullable();
                $table->timestamps();
            });
        }

        $status = app(GmailOAuthService::class)->connectionStatus();
        $json = json_encode($status);

        $this->assertTrue($status['configured']);
        $this->assertStringNotContainsString('super-secret-do-not-leak', $json);
        $this->assertArrayNotHasKey('client_secret', $status);
        $this->assertArrayNotHasKey('client_id', $status);
    }
}
