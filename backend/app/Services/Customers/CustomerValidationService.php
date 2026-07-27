<?php

namespace App\Services\Customers;

/**
 * Field validation + E.164 phone helpers for Audit A-33.
 */
class CustomerValidationService
{
    /** @var list<string> */
    public const INVALID_NAMES = [
        'unknown caller',
        'voicemail caller',
        'gmail team',
        'the google workspace team',
        'google workspace',
        'workspace notifications',
        'mail delivery subsystem',
        'noreply',
        'no-reply',
        'do not reply',
        'needs review',
    ];

    /**
     * @return list<string>
     */
    public function evaluateFlags(?string $name, ?string $phone, ?string $email, ?string $address = null): array
    {
        $flags = [];

        if ($this->isInvalidName($name)) {
            $flags[] = 'invalid_name';
        }
        if ($name !== null && str_contains($name, '@')) {
            if (! in_array('invalid_name', $flags, true)) {
                $flags[] = 'invalid_name';
            }
            $flags[] = 'email_in_name';
        }

        if ($phone === null || trim($phone) === '') {
            $flags[] = 'phone_missing';
        } elseif (! $this->isValidPhone($phone)) {
            $flags[] = 'phone_invalid';
            if (str_contains($phone, '@')) {
                $flags[] = 'email_in_phone';
            }
            if (preg_match('/[a-zA-Z]{3,}/', $phone) && ! preg_match('/^\+?[\d\s\-().extEXT]+$/', $phone)) {
                $flags[] = 'name_in_phone';
            }
        }

        if ($email === null || trim($email) === '') {
            $flags[] = 'email_missing';
        } elseif (! filter_var(strtolower(trim($email)), FILTER_VALIDATE_EMAIL)) {
            $flags[] = 'email_invalid';
        }

        return array_values(array_unique($flags));
    }

    public function isInvalidName(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return true;
        }
        $lower = strtolower(trim($name));
        foreach (self::INVALID_NAMES as $blocked) {
            if ($lower === $blocked || str_contains($lower, $blocked)) {
                return true;
            }
        }

        return str_contains($name, '@');
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

    public function isValidEmail(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return (bool) filter_var(strtolower(trim($email)), FILTER_VALIDATE_EMAIL);
    }

    public function normalizePhoneE164(?string $phone): ?string
    {
        if (! $this->isValidPhone($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';
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

    public function phoneMatchKey(?string $phone): ?string
    {
        $e164 = $this->normalizePhoneE164($phone);
        if (! $e164) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $e164) ?: '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /**
     * @param  list<string>|null  $flags
     */
    public function hasQualityIssues(?array $flags): bool
    {
        return is_array($flags) && $flags !== [];
    }
}
