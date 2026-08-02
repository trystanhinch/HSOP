<?php

namespace App\Services\Gmail;

use App\Models\GmailOauthToken;
use App\Models\GmailProcessedMessage;
use App\Services\LeadIntake\LeadEmailParser;
use App\Services\LeadIntake\LeadIntakeQuarantineService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GmailInboxFetcher
{
    public function __construct(
        private GmailOAuthService $oauth,
        private LeadEmailParser $parser,
        private LeadIntakeQuarantineService $quarantine,
    ) {}

    /**
     * @return array{fetched: int, processed: int, skipped: int, failed: int, quarantined: int, ignored: int, leads: list<int>}
     */
    public function fetchAndProcess(?string $mailbox = null): array
    {
        $stats = [
            'fetched' => 0,
            'processed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'quarantined' => 0,
            'ignored' => 0,
            'leads' => [],
        ];

        if (! config('gmail.enabled', true)) {
            return $stats + ['disabled' => true];
        }

        $accessToken = $this->oauth->getValidAccessToken($mailbox);
        $mailboxEmail = $mailbox
            ?: GmailOauthToken::query()->latest('id')->value('mailbox_email')
            ?: config('gmail.mailbox');

        $list = Http::withToken($accessToken)
            ->timeout(30)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
                'q' => config('gmail.poll_query'),
                'maxResults' => config('gmail.max_results', 25),
            ]);

        if (! $list->successful()) {
            throw new \RuntimeException('Gmail messages.list failed: '.$list->status().' '.$list->body());
        }

        $messages = $list->json('messages') ?? [];
        $stats['fetched'] = count($messages);

        foreach ($messages as $messageRef) {
            $messageId = $messageRef['id'] ?? null;
            if (! $messageId) {
                continue;
            }

            if (GmailProcessedMessage::query()->where('gmail_message_id', $messageId)->exists()) {
                $stats['skipped']++;
                continue;
            }

            try {
                $rawEmail = $this->fetchMessageAsRawEmail($accessToken, $messageId);
                $parsed = $this->parser->parse($rawEmail);
                $result = $this->quarantine->ingest($rawEmail, $parsed, [
                    'channel' => 'gmail',
                    'mailbox_email' => $mailboxEmail,
                    'gmail_message_id' => $messageId,
                    'gmail_thread_id' => $messageRef['threadId'] ?? null,
                    'send_notifications' => true,
                ]);

                $status = match ($result->outcome) {
                    'quarantined' => 'quarantined',
                    'ignored' => 'ignored',
                    'duplicate' => 'skipped_duplicate',
                    default => 'processed',
                };

                GmailProcessedMessage::create([
                    'gmail_message_id' => $messageId,
                    'gmail_thread_id' => $messageRef['threadId'] ?? null,
                    'mailbox_email' => $mailboxEmail,
                    'lead_id' => $result->lead?->id,
                    'status' => $status,
                    'error' => $result->quarantine?->quarantine_reason,
                    'processed_at' => now(),
                ]);

                if ($result->lead?->id) {
                    $stats['leads'][] = $result->lead->id;
                }
                if ($result->outcome === 'quarantined') {
                    $stats['quarantined']++;
                } elseif ($result->outcome === 'ignored') {
                    $stats['ignored']++;
                }
                $stats['processed']++;
            } catch (Throwable $e) {
                Log::error('Gmail message processing failed', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);

                GmailProcessedMessage::create([
                    'gmail_message_id' => $messageId,
                    'gmail_thread_id' => $messageRef['threadId'] ?? null,
                    'mailbox_email' => $mailboxEmail,
                    'lead_id' => null,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'processed_at' => now(),
                ]);

                $stats['failed']++;
            }
        }

        GmailOauthToken::query()
            ->where('mailbox_email', $mailboxEmail)
            ->update([
                'last_fetched_at' => now(),
                'staleness_alerted' => false,
            ]);

        return $stats;
    }

    public function fetchMessageAsRawEmail(string $accessToken, string $messageId): string
    {
        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get("https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}", [
                'format' => 'full',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Gmail messages.get failed: '.$response->status());
        }

        $payload = $response->json();
        $headers = collect($payload['payload']['headers'] ?? []);
        $subject = $headers->firstWhere('name', 'Subject')['value']
            ?? $headers->first(fn ($h) => strcasecmp($h['name'] ?? '', 'Subject') === 0)['value']
            ?? '';
        $from = $headers->first(fn ($h) => strcasecmp($h['name'] ?? '', 'From') === 0)['value'] ?? '';
        $date = $headers->first(fn ($h) => strcasecmp($h['name'] ?? '', 'Date') === 0)['value'] ?? '';

        $body = $this->extractBodyText($payload['payload'] ?? []);

        // LeadEmailParser expects a Subject: line + labeled body (Format A/B fixtures).
        // From/Date are kept for quarantine allow-list / ignore rules but are NOT mapped to phone.
        $parts = array_filter([
            $subject !== '' ? 'Subject: '.$subject : null,
            $from !== '' ? 'From: '.$from : null,
            $date !== '' ? 'Date: '.$date : null,
            '',
            trim($body),
        ], fn ($p) => $p !== null);

        return implode("\n", $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBodyText(array $payload): string
    {
        if (! empty($payload['body']['data'])) {
            return $this->decodeBody($payload['body']['data']);
        }

        $parts = $payload['parts'] ?? [];
        $plain = null;
        $html = null;

        foreach ($parts as $part) {
            $mime = strtolower($part['mimeType'] ?? '');
            $data = $part['body']['data'] ?? null;

            if (! empty($part['parts'])) {
                $nested = $this->extractBodyText($part);
                if ($nested !== '') {
                    return $nested;
                }
            }

            if (! $data) {
                continue;
            }

            if ($mime === 'text/plain' && $plain === null) {
                $plain = $this->decodeBody($data);
            }
            if ($mime === 'text/html' && $html === null) {
                $html = $this->decodeBody($data);
            }
        }

        if ($plain) {
            return $plain;
        }

        if ($html) {
            return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $payload['snippet'] ?? '';
    }

    private function decodeBody(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $decoded = base64_decode($data, true);

        return $decoded === false ? '' : $decoded;
    }
}
