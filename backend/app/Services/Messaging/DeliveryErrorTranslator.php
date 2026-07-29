<?php

namespace App\Services\Messaging;

/**
 * A-21 — Translate provider / gate codes into plain-language fixes.
 */
class DeliveryErrorTranslator
{
    /**
     * @return array{code: string, plain: string, correction_path: string}
     */
    public function translate(?string $reason, ?string $rawMessage = null, string $channel = 'sms'): array
    {
        $reason = $reason ?: 'unknown';
        $raw = trim((string) $rawMessage);

        $map = [
            'no_phone' => [
                'code' => 'missing_phone',
                'plain' => 'No valid phone number on file (could not format to E.164).',
                'correction_path' => 'Update the customer/user phone, then retry.',
            ],
            'no_email' => [
                'code' => 'missing_email',
                'plain' => 'No email address on file for this recipient.',
                'correction_path' => 'Update the customer/user email, then retry.',
            ],
            'sms_disabled' => [
                'code' => 'policy_disabled',
                'plain' => 'SMS policy is turned off in Settings → Notifications.',
                'correction_path' => 'Enable SMS policy only after Twilio is ready, then retry.',
            ],
            'email_disabled' => [
                'code' => 'policy_disabled',
                'plain' => 'Email policy is turned off in Settings → Notifications.',
                'correction_path' => 'Enable email policy only after the mail provider is ready, then retry.',
            ],
            'provider_unavailable' => [
                'code' => 'provider_unavailable',
                'plain' => $channel === 'sms'
                    ? 'SMS policy is ON but Twilio is not configured/ready.'
                    : 'Email policy is ON but the mail provider is not configured/ready.',
                'correction_path' => 'Fix provider credentials in .env, confirm channel health is green, then retry.',
            ],
            'user_disabled' => [
                'code' => 'user_opt_out',
                'plain' => 'This user has SMS disabled on their account.',
                'correction_path' => 'Re-enable SMS for the user (or choose another channel), then retry.',
            ],
            'do_not_contact' => [
                'code' => 'do_not_contact',
                'plain' => 'Recipient is marked do-not-contact or has blocked this channel.',
                'correction_path' => 'Review customer communication preferences before any retry.',
            ],
            'test_data' => [
                'code' => 'test_data_blocked',
                'plain' => 'Blocked because this record is flagged as test data (A-05).',
                'correction_path' => 'Do not retry to real customers from test records.',
            ],
            'send_failed' => [
                'code' => 'provider_reject',
                'plain' => $raw !== '' ? $this->humanizeProvider($raw, $channel) : 'Provider rejected the message.',
                'correction_path' => 'Fix the recipient or content based on the provider message, then retry.',
            ],
            'template_inactive' => [
                'code' => 'template_inactive',
                'plain' => 'The message template for this event is inactive, so no message was sent.',
                'correction_path' => 'Re-activate the template in Settings → Message Templates if this send should resume.',
            ],
        ];

        if (isset($map[$reason])) {
            return $map[$reason];
        }

        if ($raw !== '') {
            return [
                'code' => 'provider_error',
                'plain' => $this->humanizeProvider($raw, $channel),
                'correction_path' => 'Review the provider response, correct contact info if needed, then retry.',
            ];
        }

        return [
            'code' => 'unknown',
            'plain' => 'Delivery failed for an unknown reason.',
            'correction_path' => 'Inspect the raw error and channel health, then retry.',
        ];
    }

    private function humanizeProvider(string $raw, string $channel): string
    {
        $lower = strtolower($raw);

        if (str_contains($lower, 'unsubscribed') || str_contains($lower, 'opt out')) {
            return 'Recipient opted out of this channel with the provider.';
        }
        if (str_contains($lower, 'invalid') && (str_contains($lower, 'phone') || str_contains($lower, 'to'))) {
            return 'Provider rejected the phone number as invalid.';
        }
        if (str_contains($lower, 'authenticate') || str_contains($lower, 'credential') || str_contains($lower, 'unauthorized')) {
            return $channel === 'sms'
                ? 'Twilio authentication failed — check SID / auth token.'
                : 'Mail provider authentication failed — check API key / SMTP credentials.';
        }
        if (str_contains($lower, 'from') && str_contains($lower, 'not')) {
            return 'Sender identity is not verified with the provider.';
        }

        // Keep raw but trim length for UI.
        return mb_strlen($raw) > 220 ? mb_substr($raw, 0, 217).'…' : $raw;
    }
}
