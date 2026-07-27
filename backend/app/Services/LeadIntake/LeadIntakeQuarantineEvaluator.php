<?php

namespace App\Services\LeadIntake;

use App\Models\CompanySource;

/**
 * Field validation, confidence scoring, allow-list, and ignore rules for Gmail intake.
 */
class LeadIntakeQuarantineEvaluator
{
    /** @var list<string> */
    private const BLOCKED_NAMES = [
        'unknown caller',
        'gmail team',
        'the google workspace team',
        'google workspace',
        'workspace notifications',
        'mail delivery subsystem',
        'noreply',
        'no-reply',
        'do not reply',
    ];

    /** @var list<string> */
    private const IGNORE_SENDER_NEEDLES = [
        'google.com',
        'accounts.google.com',
        'workspace.google.com',
        'gmail.com', // system notices often from noreply@gmail.com — still check subject
        'mailer-daemon',
        'postmaster',
    ];

    /** @var list<string> */
    private const IGNORE_SUBJECT_NEEDLES = [
        'security alert',
        'new sign-in',
        'signin attempt',
        '2-step verification',
        'oauth',
        'access for less secure',
        'verify your',
        'newsletter',
        'unsubscribe',
        'weekly digest',
        'your google workspace',
        'admin alert',
        'suspicious activity',
        'password reset',
        'app password',
    ];

    /** @var list<string> */
    private const INTERNAL_DOMAINS = [
        'serviceop.ca',
        'hsop.ca',
        'acuteradrywall.ca',
    ];

    /**
     * @return array{
     *   action: 'auto_approve'|'quarantine'|'ignore',
     *   reason: string,
     *   parsed_fields: array<string, mixed>,
     *   field_confidence: list<array{field: string, score: int, source_text: ?string, valid: bool}>,
     *   validation_errors: list<string>,
     *   company_source_id: ?int,
     *   from_header: ?string,
     *   subject: ?string,
     *   duplicate_group_key: ?string
     * }
     */
    public function evaluate(ParsedLeadEmail $parsed, string $rawEmail): array
    {
        $fromHeader = $this->extractHeader($rawEmail, 'From');
        $subject = $parsed->subject ?: $this->extractHeader($rawEmail, 'Subject');

        $sanitized = $this->sanitizeParsed($parsed, $rawEmail);
        $fields = $sanitized['fields'];
        $confidence = $sanitized['confidence'];
        $errors = $sanitized['errors'];

        if ($ignore = $this->detectIgnore($fromHeader, $subject, $rawEmail)) {
            return [
                'action' => 'ignore',
                'reason' => $ignore,
                'parsed_fields' => $fields,
                'field_confidence' => $confidence,
                'validation_errors' => $errors,
                'company_source_id' => null,
                'from_header' => $fromHeader,
                'subject' => $subject,
                'duplicate_group_key' => $this->voicemailDuplicateKey($parsed, $fields),
            ];
        }

        $source = $this->matchAllowList($fromHeader, $subject, $parsed, $rawEmail);
        if (! $source) {
            $errors[] = 'no matching source rule / sender not on allow-list';

            return [
                'action' => 'quarantine',
                'reason' => 'no matching source rule',
                'parsed_fields' => $fields,
                'field_confidence' => $confidence,
                'validation_errors' => $errors,
                'company_source_id' => null,
                'from_header' => $fromHeader,
                'subject' => $subject,
                'duplicate_group_key' => $this->voicemailDuplicateKey($parsed, $fields),
            ];
        }

        $phoneValid = $this->isValidPhone($fields['phone'] ?? null);
        $nameOk = $this->isAcceptableName($fields['contact_name'] ?? null);
        $minScore = $this->minConfidence($confidence);

        if (! $phoneValid) {
            $errors[] = 'phone missing or invalid (email/name rejected from phone column)';
        }
        if (! $nameOk && ($fields['contact_name'] ?? null)) {
            $errors[] = 'blocked or placeholder contact name';
        }

        // Auto-approve only when phone is valid, name acceptable (or voicemail), source matched,
        // and overall confidence is not critically low.
        $canAuto = $phoneValid
            && ($nameOk || $parsed->isVoicemail())
            && $minScore >= 40
            && $errors === [];

        // Voicemail without name is OK if phone valid — still quarantine for human scope review
        // unless we have a clear source match AND phone. Spec: low-confidence stays in review.
        if ($parsed->isVoicemail()) {
            if (! $phoneValid) {
                return [
                    'action' => 'quarantine',
                    'reason' => 'voicemail with invalid or missing phone',
                    'parsed_fields' => $fields,
                    'field_confidence' => $confidence,
                    'validation_errors' => $errors,
                    'company_source_id' => $source->id,
                    'from_header' => $fromHeader,
                    'subject' => $subject,
                    'duplicate_group_key' => $this->voicemailDuplicateKey($parsed, $fields),
                ];
            }

            // Valid voicemail phone + allow-list → still quarantine for review (no name/description),
            // unless duplicate merge happens upstream. Spec test 1 is form lead auto-create;
            // voicemail duplicates are test 4. Quarantine single voicemails for review.
            return [
                'action' => 'quarantine',
                'reason' => 'voicemail requires human review (no name/description)',
                'parsed_fields' => $fields,
                'field_confidence' => $confidence,
                'validation_errors' => $errors,
                'company_source_id' => $source->id,
                'from_header' => $fromHeader,
                'subject' => $subject,
                'duplicate_group_key' => $this->voicemailDuplicateKey($parsed, $fields),
            ];
        }

        if (! $canAuto) {
            return [
                'action' => 'quarantine',
                'reason' => $errors[0] ?? 'low confidence / incomplete fields',
                'parsed_fields' => $fields,
                'field_confidence' => $confidence,
                'validation_errors' => $errors,
                'company_source_id' => $source->id,
                'from_header' => $fromHeader,
                'subject' => $subject,
                'duplicate_group_key' => null,
            ];
        }

        return [
            'action' => 'auto_approve',
            'reason' => 'passed validation and allow-list',
            'parsed_fields' => $fields,
            'field_confidence' => $confidence,
            'validation_errors' => [],
            'company_source_id' => $source->id,
            'from_header' => $fromHeader,
            'subject' => $subject,
            'duplicate_group_key' => null,
        ];
    }

    public function isValidPhone(?string $phone): bool
    {
        if ($phone === null || trim($phone) === '') {
            return false;
        }
        if (str_contains($phone, '@')) {
            return false;
        }
        if (preg_match('/[a-zA-Z]/', $phone) && ! preg_match('/^\+?[\d\s\-().extEXT]+$/', $phone)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return strlen($digits) >= 10 && strlen($digits) <= 15;
    }

    public function isAcceptableName(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }
        $lower = strtolower(trim($name));
        foreach (self::BLOCKED_NAMES as $blocked) {
            if ($lower === $blocked || str_contains($lower, $blocked)) {
                return false;
            }
        }
        if (str_contains($name, '@')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{fields: array<string, mixed>, confidence: list<array{field: string, score: int, source_text: ?string, valid: bool}>, errors: list<string>}
     */
    private function sanitizeParsed(ParsedLeadEmail $parsed, string $rawEmail): array
    {
        $errors = [];
        $confidence = [];

        $rawPhone = $parsed->phone;
        $phoneSource = $this->findSourceSnippet($rawEmail, ['Phone', 'Telephone', 'Mobile', 'Caller ID', 'Caller Number', 'From']);
        $phoneValid = $this->isValidPhone($rawPhone);
        if ($rawPhone && ! $phoneValid) {
            $errors[] = 'phone field contained non-phone value (cleared)';
        }
        $phone = $phoneValid ? $this->normalizePhoneDigits($rawPhone) : null;
        $confidence[] = [
            'field' => 'phone',
            'score' => $phoneValid ? 95 : ($rawPhone ? 5 : 0),
            'source_text' => $phoneSource ?: $rawPhone,
            'valid' => $phoneValid,
        ];

        $email = $parsed->email;
        $emailValid = $email && filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($email && ! $emailValid) {
            $errors[] = 'email format invalid';
            $email = null;
        }
        $confidence[] = [
            'field' => 'email',
            'score' => $emailValid ? 98 : ($parsed->email ? 20 : 30),
            'source_text' => $this->findSourceSnippet($rawEmail, ['E-mail', 'Email', 'Email Address']) ?: $parsed->email,
            'valid' => (bool) $emailValid,
        ];

        $name = $parsed->contactName();
        $nameOk = $this->isAcceptableName($name);
        if ($name && ! $nameOk) {
            $errors[] = 'contact name rejected';
            $name = null;
        }
        $confidence[] = [
            'field' => 'contact_name',
            'score' => $nameOk ? 90 : ($parsed->contactName() ? 10 : ($parsed->isVoicemail() ? 40 : 15)),
            'source_text' => $this->findSourceSnippet($rawEmail, ['First Name', 'Last Name', 'Name', 'Contact Name']) ?: $parsed->contactName(),
            'valid' => $nameOk,
        ];

        $desc = $parsed->projectDescription ?: $parsed->serviceRequested;
        $confidence[] = [
            'field' => 'project_description',
            'score' => $desc ? 85 : ($parsed->isVoicemail() ? 35 : 25),
            'source_text' => $this->findSourceSnippet($rawEmail, ['Message', 'Text area', 'Project Description', 'Description']) ?: $desc,
            'valid' => (bool) $desc,
        ];

        $address = $parsed->address;
        $confidence[] = [
            'field' => 'address',
            'score' => $address ? 80 : 25,
            'source_text' => $this->findSourceSnippet($rawEmail, ['Address', 'City', 'Location', 'Text area']) ?: $address,
            'valid' => (bool) $address,
        ];

        return [
            'fields' => [
                'contact_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'project_description' => $desc,
                'service_requested' => $parsed->serviceRequested,
                'source_website' => $parsed->sourceWebsite,
                'source_label' => $parsed->sourceLabel,
                'email_format' => $parsed->emailFormat,
                'recording_url' => $parsed->recordingUrl,
                'call_duration' => $parsed->callDuration,
                'call_city' => $parsed->callCity,
                'submitted_at' => $parsed->submittedAt,
                'marketing_consent' => $parsed->marketingConsent,
            ],
            'confidence' => $confidence,
            'errors' => $errors,
        ];
    }

    private function detectIgnore(?string $fromHeader, ?string $subject, string $rawEmail): ?string
    {
        $from = strtolower((string) $fromHeader);
        $subj = strtolower((string) $subject);
        $bodyHead = strtolower(substr($rawEmail, 0, 2000));

        foreach (self::IGNORE_SUBJECT_NEEDLES as $needle) {
            if ($subj !== '' && str_contains($subj, $needle)) {
                return 'ignored system/newsletter subject: '.$needle;
            }
        }

        // Google Workspace / security senders
        if (str_contains($from, 'google.com') || str_contains($from, 'workspace-noreply')
            || str_contains($from, 'noreply@google') || str_contains($from, 'no-reply@google')) {
            return 'ignored Google Workspace / Google system sender';
        }

        if (str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster@')) {
            return 'ignored mailer-daemon / postmaster';
        }

        // Internal domain mail that is not a customer form/voicemail lead pattern
        foreach (self::INTERNAL_DOMAINS as $domain) {
            if (str_contains($from, '@'.$domain) && ! preg_match('/first name:|phone:|service required:|\[voicemail\]/i', $bodyHead.$subj)) {
                return 'ignored internal-domain mail without customer inquiry pattern';
            }
        }

        if (preg_match('/\bunsubscribe\b/i', $bodyHead) && preg_match('/\bnewsletter\b|\bdigest\b|\bpromotion\b/i', $subj.$bodyHead)) {
            return 'ignored newsletter-style message';
        }

        return null;
    }

    private function matchAllowList(?string $fromHeader, ?string $subject, ParsedLeadEmail $parsed, string $rawEmail): ?CompanySource
    {
        $haystack = strtolower(implode(' ', array_filter([
            $fromHeader,
            $subject,
            $parsed->sourceLabel,
            $parsed->sourceWebsite,
            $parsed->serviceRequested,
            substr($rawEmail, 0, 1500),
        ])));

        $sources = CompanySource::query()->where('status', 'active')->get();

        foreach ($sources as $source) {
            $patterns = is_array($source->intake_allow_patterns) ? $source->intake_allow_patterns : [];
            $candidates = array_filter(array_merge(
                [$source->domain, $source->sender_identity, $source->company_name],
                $patterns
            ));

            foreach ($candidates as $candidate) {
                $needle = strtolower(trim((string) $candidate));
                if ($needle !== '' && str_contains($haystack, $needle)) {
                    return $source;
                }
            }

            // Category keywords in company service list
            $cats = is_array($source->service_categories) ? $source->service_categories : [];
            foreach ($cats as $cat) {
                $c = strtolower((string) $cat);
                if ($c !== '' && str_contains($haystack, $c)) {
                    return $source;
                }
            }
        }

        // Fallback: classic matcher by source label / category text
        $matcher = app(CompanySourceMatcher::class);
        $byLabel = $matcher->match($parsed->sourceLabel ?? $parsed->sourceWebsite ?? $subject);
        if ($byLabel) {
            return $byLabel;
        }

        // Form leads with drywall/insulation keywords
        if (str_contains($haystack, 'drywall') || str_contains($haystack, 'paint')) {
            return $matcher->matchByCategory('drywall_paint');
        }
        if (str_contains($haystack, 'insulation')) {
            return $matcher->matchByCategory('insulation');
        }

        return null;
    }

    public function voicemailDuplicateKey(ParsedLeadEmail $parsed, array $fields = []): ?string
    {
        if (! $parsed->isVoicemail()) {
            return null;
        }
        $phone = $fields['phone'] ?? $parsed->phone;
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
        if (strlen($digits) >= 10) {
            return 'vm:phone:'.substr($digits, -10);
        }
        // Twilio recording SID as caller reference when present
        if ($parsed->recordingUrl && preg_match('/Recordings\/([A-Za-z0-9]+)/', $parsed->recordingUrl, $m)) {
            return 'vm:recording:'.$m[1];
        }

        return null;
    }

    private function normalizePhoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }
        if (strlen($digits) >= 10) {
            return '+'.$digits;
        }

        return null;
    }

    private function extractHeader(string $raw, string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/mi', $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function findSourceSnippet(string $raw, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (preg_match('/^'.preg_quote($label, '/').'\s*:\s*(.+)$/mi', $raw, $m)) {
                return trim($label.': '.$m[1]);
            }
        }

        return null;
    }

    /**
     * @param  list<array{field: string, score: int, source_text: ?string, valid: bool}>  $confidence
     */
    private function minConfidence(array $confidence): int
    {
        $scores = [];
        foreach ($confidence as $row) {
            if (in_array($row['field'], ['phone', 'contact_name', 'project_description'], true)) {
                $scores[] = (int) $row['score'];
            }
        }

        return $scores === [] ? 0 : min($scores);
    }
}
