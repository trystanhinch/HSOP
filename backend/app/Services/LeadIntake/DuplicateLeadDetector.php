<?php

namespace App\Services\LeadIntake;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

class DuplicateLeadDetector
{
    public const FUZZY_NAME_THRESHOLD = 0.82;

    public const FUZZY_DESCRIPTION_THRESHOLD = 0.55;

    /**
     * @return array{is_duplicate: bool, match_type: ?string, lead: ?Lead, customer: ?Customer}
     */
    public function detect(ParsedLeadEmail $parsed): array
    {
        $phone = $this->normalizePhoneDigits($parsed->phone);
        $email = $parsed->email ? strtolower($parsed->email) : null;
        $name = $parsed->contactName();

        if ($phone) {
            $lead = Lead::query()->whereNotNull('phone')
                ->get()
                ->first(fn (Lead $l) => $this->normalizePhoneDigits($l->phone) === $phone);

            if ($lead) {
                return $this->result(true, 'exact_phone', $lead);
            }

            $customer = $this->findCustomerByPhone($phone);
            if ($customer) {
                $lead = $this->latestLeadForCustomer($customer);

                return $this->result(true, 'exact_phone', $lead, $customer);
            }
        }

        if ($email) {
            $lead = Lead::query()->whereRaw('LOWER(email) = ?', [$email])->latest()->first();
            if ($lead) {
                return $this->result(true, 'exact_email', $lead);
            }

            $customer = Customer::query()
                ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email]))
                ->first();

            if ($customer) {
                $lead = $this->latestLeadForCustomer($customer);

                return $this->result(true, 'exact_email', $lead, $customer);
            }
        }

        if ($name && $parsed->projectDescription) {
            $fuzzy = $this->findFuzzyMatch($name, $parsed->projectDescription);
            if ($fuzzy) {
                return $this->result(true, 'fuzzy_name_description', $fuzzy);
            }
        }

        return $this->result(false, null, null);
    }

    private function findCustomerByPhone(string $phoneDigits): ?Customer
    {
        return Customer::query()
            ->with('user')
            ->get()
            ->first(function (Customer $customer) use ($phoneDigits) {
                $userPhone = $customer->user?->phone;

                return $userPhone && $this->normalizePhoneDigits($userPhone) === $phoneDigits;
            });
    }

    private function latestLeadForCustomer(Customer $customer): ?Lead
    {
        return Lead::query()->where('customer_id', $customer->id)->latest()->first();
    }

    private function findFuzzyMatch(string $name, string $description): ?Lead
    {
        $candidates = Lead::query()
            ->whereNotNull('contact_name')
            ->whereNotNull('project_description')
            ->latest()
            ->limit(200)
            ->get();

        foreach ($candidates as $lead) {
            $nameScore = $this->similarityRatio($name, (string) $lead->contact_name);
            $descScore = $this->similarityRatio($description, (string) $lead->project_description);

            if ($nameScore >= self::FUZZY_NAME_THRESHOLD && $descScore >= self::FUZZY_DESCRIPTION_THRESHOLD) {
                return $lead;
            }
        }

        return null;
    }

    public function similarityRatio(string $a, string $b): float
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    private function normalizePhoneDigits(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @return array{is_duplicate: bool, match_type: ?string, lead: ?Lead, customer: ?Customer}
     */
    private function result(bool $isDuplicate, ?string $type, ?Lead $lead, ?Customer $customer = null): array
    {
        return [
            'is_duplicate' => $isDuplicate,
            'match_type' => $type,
            'lead' => $lead,
            'customer' => $customer,
        ];
    }
}
