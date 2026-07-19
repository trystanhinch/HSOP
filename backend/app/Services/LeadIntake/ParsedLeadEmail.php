<?php

namespace App\Services\LeadIntake;

class ParsedLeadEmail
{
    public function __construct(
        public readonly string $rawCopy,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $serviceRequested,
        public readonly ?string $projectDescription,
        public readonly ?string $address,
        public readonly ?string $sourceWebsite,
        public readonly ?string $submittedAt,
        public readonly ?bool $marketingConsent,
        /** @var array<string, float> field => confidence 0-1 */
        public readonly array $fieldConfidence,
        public readonly bool $needsManualReview,
        public readonly ?string $subject = null,
        /** form | voicemail */
        public readonly string $emailFormat = 'form',
        /** Original city/listing label e.g. "Coquitlam Drywall" */
        public readonly ?string $sourceLabel = null,
        public readonly ?string $recordingUrl = null,
        public readonly ?string $callDuration = null,
        public readonly ?string $callCity = null,
    ) {}

    public function contactName(): ?string
    {
        $parts = array_filter([$this->firstName, $this->lastName]);

        if ($parts) {
            return implode(' ', $parts);
        }

        if ($this->emailFormat === 'voicemail' && $this->phone) {
            return 'Voicemail caller ('.$this->phone.')';
        }

        return null;
    }

    public function isVoicemail(): bool
    {
        return $this->emailFormat === 'voicemail';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'contact_name' => $this->contactName(),
            'phone' => $this->phone,
            'email' => $this->email,
            'service_requested' => $this->serviceRequested,
            'project_description' => $this->projectDescription,
            'address' => $this->address,
            'source_website' => $this->sourceWebsite,
            'submitted_at' => $this->submittedAt,
            'marketing_consent' => $this->marketingConsent,
            'field_confidence' => $this->fieldConfidence,
            'needs_manual_review' => $this->needsManualReview,
            'subject' => $this->subject,
            'email_format' => $this->emailFormat,
            'source_label' => $this->sourceLabel,
            'recording_url' => $this->recordingUrl,
            'call_duration' => $this->callDuration,
            'call_city' => $this->callCity,
        ];
    }
}
