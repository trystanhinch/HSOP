<?php

namespace App\Services\LeadIntake;

class LeadEmailParser
{
    /** @var array<string, list<string>> */
    private const FIELD_ALIASES = [
        'first_name' => ['first name', 'firstname', 'given name'],
        'last_name' => ['last name', 'lastname', 'surname', 'family name'],
        'name' => ['name', 'full name', 'customer name', 'contact name'],
        'phone' => ['phone', 'telephone', 'mobile', 'cell', 'phone number', 'tel', 'caller', 'caller number', 'from'],
        'email' => ['email', 'e-mail', 'email address'],
        'service' => ['service', 'service required', 'service requested', 'service type', 'trade', 'work type'],
        'message' => ['message', 'text area', 'textarea', 'project description', 'description', 'details', 'project details', 'notes'],
        'address' => ['address', 'location', 'city', 'area', 'city/area'],
        'source' => ['source', 'source website', 'website', 'company', 'referral source', 'how did you hear', 'to'],
        'submitted' => ['submitted', 'date submitted', 'submitted at', 'date', 'timestamp', 'date of message creation', 'date/time', 'received'],
        'marketing_consent' => [
            'marketing consent', 'consent', 'email consent', 'sms consent', 'opt in', 'opt-in',
            'marketing solicitations prohibited',
        ],
        'duration' => ['duration', 'call duration'],
        'recording' => ['recording', 'recording url', 'voicemail url', 'recording link'],
    ];

    public function __construct(
        private KeywordCategoryClassifier $categoryClassifier = new KeywordCategoryClassifier,
    ) {}

    public function parse(string $rawInput): ParsedLeadEmail
    {
        $text = $this->normalize($rawInput);
        $subject = $this->extractSubject($text);
        $isVoicemail = $this->isVoicemailFormat($subject, $text);

        if ($isVoicemail) {
            return $this->parseVoicemail($rawInput, $text, $subject);
        }

        return $this->parseFormLead($rawInput, $text, $subject);
    }

    private function parseFormLead(string $rawInput, string $text, ?string $subject): ParsedLeadEmail
    {
        $pairs = $this->extractLabelValuePairs($text);

        $firstName = null;
        $lastName = null;
        $confidence = [];

        $nameValue = $this->getValue($pairs, 'name');
        if ($nameValue) {
            $confidence['name'] = 0.95;
            [$firstName, $lastName] = $this->splitName($nameValue);
        }

        $explicitFirst = $this->getValue($pairs, 'first_name');
        if ($explicitFirst) {
            $firstName = $explicitFirst;
            $confidence['first_name'] = 0.98;
        }

        $explicitLast = $this->getValue($pairs, 'last_name');
        if ($explicitLast) {
            $lastName = $explicitLast;
            $confidence['last_name'] = 0.98;
        }

        $phone = $this->normalizePhone($this->getValue($pairs, 'phone'));
        if ($phone) {
            $confidence['phone'] = $this->looksLikePhone($phone) ? 0.95 : 0.5;
        }

        $email = $this->normalizeEmail($this->getValue($pairs, 'email'));
        if ($email) {
            $confidence['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) ? 0.98 : 0.4;
        }

        $service = $this->getValue($pairs, 'service');
        if ($service) {
            $confidence['service_requested'] = 0.9;
        }

        $message = $this->getValue($pairs, 'message');
        if ($message) {
            $confidence['project_description'] = 0.9;
        }

        $address = $this->getValue($pairs, 'address');
        if ($address) {
            $confidence['address'] = 0.85;
        }

        $source = $this->getValue($pairs, 'source');
        if ($source) {
            $confidence['source_website'] = 0.85;
        }

        $submitted = $this->getValue($pairs, 'submitted');
        if ($submitted) {
            $confidence['submitted_at'] = 0.8;
        }

        $consentRaw = $this->getValue($pairs, 'marketing_consent');
        $marketingConsent = null;
        if ($consentRaw !== null) {
            $marketingConsent = $this->parseConsent($consentRaw);
            $confidence['marketing_consent'] = $marketingConsent === null ? 0.3 : 0.9;
        }

        if (! $email) {
            $scraped = $this->scrapeEmail($text);
            if ($scraped) {
                $email = $scraped;
                $confidence['email'] = 0.7;
            }
        }

        if (! $phone) {
            $scraped = $this->scrapePhone($text);
            if ($scraped) {
                $phone = $scraped;
                $confidence['phone'] = 0.7;
            }
        }

        $sourceLabel = $this->categoryClassifier->extractSourceLabel($subject ?? '', [
            'source_website' => $source,
        ]);

        if (! $source && $sourceLabel) {
            $source = $sourceLabel;
            $confidence['source_website'] = 0.8;
        }

        $needsReview = $this->needsManualReview($confidence, $firstName, $lastName, $phone, $email, $service, $message);

        return new ParsedLeadEmail(
            rawCopy: $rawInput,
            firstName: $firstName,
            lastName: $lastName,
            phone: $phone,
            email: $email,
            serviceRequested: $service,
            projectDescription: $message,
            address: $address,
            sourceWebsite: $source,
            submittedAt: $submitted,
            marketingConsent: $marketingConsent,
            fieldConfidence: $confidence,
            needsManualReview: $needsReview,
            subject: $subject,
            emailFormat: 'form',
            sourceLabel: $sourceLabel,
        );
    }

    private function parseVoicemail(string $rawInput, string $text, ?string $subject): ParsedLeadEmail
    {
        $pairs = $this->extractLabelValuePairs($text);
        $confidence = [];

        $phone = $this->normalizePhone(
            $this->getValue($pairs, 'phone')
            ?? $this->extractCallerFromSubject($subject)
            ?? $this->scrapePhone($text)
        );
        if ($phone) {
            $confidence['phone'] = 0.95;
        }

        $duration = $this->getValue($pairs, 'duration');
        if ($duration) {
            $confidence['call_duration'] = 0.9;
        }

        $submitted = $this->getValue($pairs, 'submitted');
        if ($submitted) {
            $confidence['submitted_at'] = 0.85;
        }

        $city = $this->getValue($pairs, 'address');
        if ($city) {
            $confidence['call_city'] = 0.8;
        }

        $recordingUrl = $this->getValue($pairs, 'recording') ?? $this->scrapeRecordingUrl($text);
        if ($recordingUrl) {
            $confidence['recording_url'] = 0.95;
        }

        $sourceLabel = $this->categoryClassifier->extractSourceLabel($subject ?? '', []);
        $toSource = $this->getValue($pairs, 'source');
        if (! $sourceLabel && $toSource) {
            $sourceLabel = $toSource;
        }

        // Voicemail never has name/email/description — always flag for human review.
        return new ParsedLeadEmail(
            rawCopy: $rawInput,
            firstName: null,
            lastName: null,
            phone: $phone,
            email: null,
            serviceRequested: null,
            projectDescription: null,
            address: $city,
            sourceWebsite: $sourceLabel,
            submittedAt: $submitted,
            marketingConsent: null,
            fieldConfidence: $confidence,
            needsManualReview: true,
            subject: $subject,
            emailFormat: 'voicemail',
            sourceLabel: $sourceLabel,
            recordingUrl: $recordingUrl,
            callDuration: $duration,
            callCity: $city,
        );
    }

    private function isVoicemailFormat(?string $subject, string $text): bool
    {
        if ($subject && preg_match('/\[Voicemail\]/i', $subject)) {
            return true;
        }

        return (bool) preg_match('/recording\s*url|api\.twilio\.com\/.*Recordings/i', $text);
    }

    private function extractSubject(string $text): ?string
    {
        if (preg_match('/^Subject:\s*(.+)$/mi', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function extractCallerFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        if (preg_match('/From\s+(\+?\d[\d\s\-().]{8,})\s+to\b/i', $subject, $m)) {
            return $m[1];
        }

        return null;
    }

    private function scrapeRecordingUrl(string $text): ?string
    {
        if (preg_match('#https?://[^\s]+Recordings/[A-Za-z0-9]+#i', $text, $m)) {
            return rtrim($m[0], '.,);');
        }
        if (preg_match('#https?://api\.twilio\.com/[^\s]+#i', $text, $m)) {
            return rtrim($m[0], '.,);');
        }

        return null;
    }

    private function normalize(string $input): string
    {
        $text = strip_tags($input);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * @return array<string, string>
     */
    private function extractLabelValuePairs(string $text): array
    {
        $pairs = [];
        $lines = preg_split('/\n+/', $text) ?: [];
        $messageKeys = [
            'message', 'text area', 'textarea', 'project description', 'description',
            'details', 'project details', 'notes',
        ];
        $currentLabel = null;
        $buffer = [];

        $flush = function () use (&$pairs, &$currentLabel, &$buffer): void {
            if ($currentLabel !== null && $buffer !== []) {
                $pairs[$currentLabel] = trim(implode("\n", $buffer));
            }
            $currentLabel = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($currentLabel !== null) {
                    $buffer[] = '';
                }
                continue;
            }

            // Skip subject line — handled separately
            if (preg_match('/^Subject:\s*/i', $line)) {
                continue;
            }

            if (preg_match('/^([^:]{2,80}):\s*(.*)$/u', $line, $m)) {
                $flush();
                $label = $this->normalizeLabel($m[1]);
                $value = trim($m[2]);
                if (in_array($label, $messageKeys, true)) {
                    $currentLabel = $label;
                    if ($value !== '') {
                        $buffer[] = $value;
                    }
                } elseif ($label !== '' && $value !== '') {
                    $pairs[$label] = $value;
                } elseif ($label !== '' && $value === '' && in_array($label, $messageKeys, true)) {
                    $currentLabel = $label;
                }
                continue;
            }

            if ($currentLabel !== null) {
                $buffer[] = $line;
            }
        }

        $flush();

        return $pairs;
    }

    private function normalizeLabel(string $label): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function getValue(array $pairs, string $canonical): ?string
    {
        $aliases = self::FIELD_ALIASES[$canonical] ?? [$canonical];

        foreach ($aliases as $alias) {
            if (isset($pairs[$alias])) {
                return trim($pairs[$alias]);
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return [null, null];
        }

        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [$first, $last ?: null];
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null || strlen($digits) < 10) {
            return trim($phone);
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        return trim($phone);
    }

    private function looksLikePhone(string $phone): bool
    {
        return (bool) preg_match('/\d{3}.*\d{3}.*\d{4}/', $phone);
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email !== '' ? $email : null;
    }

    private function parseConsent(string $raw): ?bool
    {
        $lower = strtolower(trim($raw));

        // "Marketing Solicitations Prohibited: accepted" → accepted prohibition → no marketing
        if (in_array($lower, ['accepted', 'prohibited'], true)) {
            return false;
        }
        // "Marketing Solicitations Prohibited: declined" → declined prohibition → marketing OK
        if ($lower === 'declined') {
            return true;
        }

        if (in_array($lower, ['yes', 'y', 'true', '1', 'agree', 'opted in', 'opt-in'], true)) {
            return true;
        }
        if (in_array($lower, ['no', 'n', 'false', '0', 'decline', 'opted out', 'opt-out'], true)) {
            return false;
        }

        return null;
    }

    private function scrapeEmail(string $text): ?string
    {
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $text, $m)) {
            return strtolower($m[0]);
        }

        return null;
    }

    private function scrapePhone(string $text): ?string
    {
        if (preg_match('/(?:\+?1[-.\s]?)?(?:\(?\d{3}\)?[-.\s]?)\d{3}[-.\s]?\d{4}/', $text, $m)) {
            return $this->normalizePhone($m[0]);
        }

        return null;
    }

    /**
     * @param  array<string, float>  $confidence
     */
    private function needsManualReview(
        array $confidence,
        ?string $firstName,
        ?string $lastName,
        ?string $phone,
        ?string $email,
        ?string $service,
        ?string $message,
    ): bool {
        foreach ($confidence as $score) {
            if ($score < 0.6) {
                return true;
            }
        }

        if (! $firstName && ! $lastName) {
            return true;
        }

        if (! $phone && ! $email) {
            return true;
        }

        if (! $service && ! $message) {
            return true;
        }

        if ($service && $this->isWeakServiceValue($service)) {
            return true;
        }

        return false;
    }

    private function isWeakServiceValue(string $service): bool
    {
        $lower = strtolower(trim($service));

        return in_array($lower, ['not sure', '(not sure)', 'unknown', 'n/a', 'na', 'other', 'unsure'], true);
    }
}
