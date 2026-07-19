<?php

namespace App\Services\Gmail;

use App\Models\GmailOauthToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GmailOAuthService
{
    public function isConfigured(): bool
    {
        return (bool) (config('gmail.client_id') && config('gmail.client_secret') && config('gmail.redirect_uri'));
    }

    public function authorizationUrl(int $userId): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Gmail OAuth is not configured. Set GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET, and GOOGLE_REDIRECT_URI.');
        }

        $state = Str::random(40);
        cache()->put($this->stateCacheKey($state), [
            'user_id' => $userId,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => config('gmail.client_id'),
            'redirect_uri' => config('gmail.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('gmail.scopes')),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent', // force refresh_token on re-auth
            'state' => $state,
            'login_hint' => config('gmail.mailbox'),
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @return array{token: GmailOauthToken, mailbox: string}
     */
    public function handleCallback(string $code, string $state): array
    {
        $cached = cache()->pull($this->stateCacheKey($state));
        if (! $cached || empty($cached['user_id'])) {
            throw new RuntimeException('Invalid or expired OAuth state. Start the connect flow again.');
        }

        $tokenResponse = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => config('gmail.client_id'),
                'client_secret' => config('gmail.client_secret'),
                'redirect_uri' => config('gmail.redirect_uri'),
                'grant_type' => 'authorization_code',
            ]);

        if (! $tokenResponse->successful()) {
            Log::error('Gmail OAuth token exchange failed', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);
            throw new RuntimeException('Google token exchange failed.');
        }

        $payload = $tokenResponse->json();
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        $scope = $payload['scope'] ?? implode(' ', config('gmail.scopes'));

        if (! $accessToken) {
            throw new RuntimeException('Google did not return an access token.');
        }

        $mailbox = $this->fetchMailboxEmail($accessToken) ?: config('gmail.mailbox');

        $record = GmailOauthToken::query()->firstOrNew(['mailbox_email' => $mailbox]);
        $record->mailbox_email = $mailbox;
        $record->scope = $scope;
        $record->connected_by = $cached['user_id'];
        $record->connected_at = now();

        $existingRefresh = $record->exists ? $record->plainRefreshToken() : null;
        $refreshToStore = $refreshToken ?: $existingRefresh;

        if (! $refreshToStore) {
            throw new RuntimeException('No refresh token received. Revoke prior access at myaccount.google.com/permissions and reconnect with prompt=consent.');
        }

        $record->access_token_encrypted = \Illuminate\Support\Facades\Crypt::encryptString($accessToken);
        $record->refresh_token_encrypted = \Illuminate\Support\Facades\Crypt::encryptString($refreshToStore);
        $record->access_token_expires_at = now()->addSeconds($expiresIn);
        $record->save();

        return ['token' => $record->fresh(), 'mailbox' => $mailbox];
    }

    public function getValidAccessToken(?string $mailbox = null): string
    {
        $mailbox = $mailbox ?: config('gmail.mailbox');
        $record = GmailOauthToken::query()->where('mailbox_email', $mailbox)->first()
            ?? GmailOauthToken::query()->latest('id')->first();

        if (! $record || ! $record->plainRefreshToken()) {
            throw new RuntimeException('Gmail inbox is not connected. Complete OAuth first.');
        }

        if (! $record->accessTokenExpired() && $record->plainAccessToken()) {
            return $record->plainAccessToken();
        }

        return $this->refreshAccessToken($record);
    }

    public function connectionStatus(): array
    {
        $record = GmailOauthToken::query()
            ->where('mailbox_email', config('gmail.mailbox'))
            ->latest('id')
            ->first()
            ?? GmailOauthToken::query()->latest('id')->first();

        return [
            'configured' => $this->isConfigured(),
            'connected' => (bool) ($record && $record->plainRefreshToken()),
            'mailbox_email' => $record?->mailbox_email,
            'expected_mailbox' => config('gmail.mailbox'),
            'connected_at' => $record?->connected_at,
            'last_fetched_at' => $record?->last_fetched_at,
            'scope' => $record?->scope,
            'redirect_uri' => config('gmail.redirect_uri'),
            'scopes' => config('gmail.scopes'),
        ];
    }

    public function disconnect(?string $mailbox = null): void
    {
        $mailbox = $mailbox ?: config('gmail.mailbox');
        GmailOauthToken::query()->where('mailbox_email', $mailbox)->delete();
    }

    private function refreshAccessToken(GmailOauthToken $record): string
    {
        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('gmail.client_id'),
                'client_secret' => config('gmail.client_secret'),
                'refresh_token' => $record->plainRefreshToken(),
                'grant_type' => 'refresh_token',
            ]);

        if (! $response->successful()) {
            Log::error('Gmail OAuth refresh failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to refresh Gmail access token. Reconnect the inbox.');
        }

        $payload = $response->json();
        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);

        if (! $accessToken) {
            throw new RuntimeException('Refresh response missing access_token.');
        }

        $record->storeSecrets($accessToken, null, now()->addSeconds($expiresIn));

        return $accessToken;
    }

    private function fetchMailboxEmail(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');

        if (! $response->successful()) {
            return null;
        }

        return $response->json('emailAddress');
    }

    private function stateCacheKey(string $state): string
    {
        return 'gmail_oauth_state:'.$state;
    }
}
