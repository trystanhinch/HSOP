<?php

namespace App\Services\Company;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A-13 — Legal entity identity (distinct from Brand Content operating brand / A-06).
 */
class CompanyIdentityService
{
    /** Fields that require owner password re-confirmation. */
    public const SENSITIVE_FIELDS = [
        'legal_name',
        'gst_number',
        'gst_verification_status',
        'remittance_address',
    ];

    /** Fields required for production identity readiness. */
    public const REQUIRED_FOR_PRODUCTION = [
        'legal_name',
        'operating_name',
        'remittance_address',
        'province',
        'timezone',
        'currency',
        'gst_number',
        'public_contact_email',
        'public_contact_phone',
        'invoice_prefix',
    ];

    /**
     * @return array{complete: bool, missing: list<string>, is_test_data: bool, environment: string}
     */
    public function readiness(?Company $company = null): array
    {
        $company ??= Company::withTestData()->orderBy('id')->first();
        $missing = [];

        if (! $company) {
            return [
                'complete' => false,
                'missing' => self::REQUIRED_FOR_PRODUCTION,
                'is_test_data' => false,
                'environment' => config('app.env', 'production'),
            ];
        }

        foreach (self::REQUIRED_FOR_PRODUCTION as $field) {
            $value = $company->{$field} ?? null;
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }

        if (($company->gst_verification_status ?? '') === 'unverified') {
            $missing[] = 'gst_verification_status';
            $missing = array_values(array_unique($missing));
        }

        return [
            'complete' => $missing === [],
            'missing' => $missing,
            'is_test_data' => (bool) ($company->is_test_data ?? false),
            'environment' => config('app.env', 'production'),
            'blocking' => ! (bool) ($company->is_test_data ?? false) && $missing !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed> sanitized company attributes to apply
     */
    public function validateAndPrepare(array $incoming): array
    {
        $out = [];

        foreach ([
            'name', 'legal_name', 'operating_name', 'email', 'phone', 'address',
            'remittance_address', 'province', 'timezone', 'currency', 'gst_number',
            'gst_verification_status', 'invoice_prefix', 'public_contact_email',
            'public_contact_phone',
        ] as $field) {
            if (! array_key_exists($field, $incoming) || $incoming[$field] === null) {
                continue;
            }
            $out[$field] = is_string($incoming[$field]) ? trim($incoming[$field]) : $incoming[$field];
        }

        if (isset($out['public_contact_email']) && $out['public_contact_email'] !== ''
            && ! filter_var($out['public_contact_email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'public_contact_email' => 'Public contact email must be a valid email address.',
            ]);
        }

        if (isset($out['email']) && $out['email'] !== ''
            && ! filter_var($out['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Company email must be a valid email address.',
            ]);
        }

        if (isset($out['gst_number']) && $out['gst_number'] !== '') {
            $gst = preg_replace('/\s+/', '', strtoupper((string) $out['gst_number']));
            // Canadian BN / GST: 9 digits + optional RT0001
            if (! preg_match('/^\d{9}(RT\d{4})?$/', $gst)) {
                throw ValidationException::withMessages([
                    'gst_number' => 'GST number must look like 123456789 or 123456789RT0001.',
                ]);
            }
            $out['gst_number'] = $gst;
        }

        if (isset($out['gst_verification_status'])) {
            $allowed = ['unverified', 'pending', 'verified', 'failed'];
            if (! in_array($out['gst_verification_status'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'gst_verification_status' => 'Invalid GST verification status.',
                ]);
            }
        }

        if (isset($out['currency'])) {
            $out['currency'] = strtoupper((string) $out['currency']);
            if (! in_array($out['currency'], ['CAD', 'USD'], true)) {
                throw ValidationException::withMessages([
                    'currency' => 'Currency must be CAD or USD.',
                ]);
            }
        }

        if (isset($out['public_contact_phone']) && $out['public_contact_phone'] !== '') {
            $digits = preg_replace('/\D+/', '', (string) $out['public_contact_phone']);
            if (strlen($digits) < 10 || strlen($digits) > 15) {
                throw ValidationException::withMessages([
                    'public_contact_phone' => 'Public contact phone must include 10–15 digits.',
                ]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function assertSensitiveConfirmation(
        User $actor,
        array $changes,
        ?string $password,
        bool $confirmed,
        ?Company $company = null
    ): void {
        $touching = [];
        foreach (array_intersect(array_keys($changes), self::SENSITIVE_FIELDS) as $field) {
            $new = $changes[$field] ?? null;
            $old = $company?->{$field};
            if ((string) ($old ?? '') === (string) ($new ?? '')) {
                continue;
            }
            $touching[] = $field;
        }

        if ($touching === []) {
            return;
        }

        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_sensitive_change' => 'Tax identity and remittance address changes require explicit confirmation.',
                'sensitive_fields' => array_values($touching),
            ]);
        }

        if (! $password || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Re-enter your password to change tax identity or remittance address.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function apply(Company $company, array $changes, User $actor): Company
    {
        if ($changes === []) {
            return $company;
        }

        $previous = [];
        $next = [];
        foreach ($changes as $key => $value) {
            $old = $company->{$key};
            if ((string) ($old ?? '') === (string) ($value ?? '')) {
                continue;
            }
            $previous[$key] = $old;
            $next[$key] = $value;
        }

        if ($next === []) {
            return $company;
        }

        $company->update($next);

        // Keep legacy invoice number format setting aligned with invoice_prefix when set.
        if (isset($next['invoice_prefix']) && trim((string) $next['invoice_prefix']) !== '') {
            $fmt = rtrim((string) $next['invoice_prefix'], '-').'-{XXXX}';
            \App\Models\Setting::set('invoice_number_format', $fmt);
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'object_type' => 'company',
            'object_id' => $company->id,
            'action_type' => 'company_identity_updated',
            'previous_value' => $previous,
            'new_value' => array_merge($next, [
                'effective_at' => now()->toIso8601String(),
                'sensitive' => array_values(array_intersect(array_keys($next), self::SENSITIVE_FIELDS)),
            ]),
            'created_at' => now(),
        ]);

        return $company->fresh();
    }
}
