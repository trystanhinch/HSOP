<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CustomerDuplicateDetector
{
    public function __construct(
        private CustomerValidationService $validation,
    ) {}

    /**
     * Assign duplicate_group_id + is_duplicate_primary for production customers.
     * Does not change name/phone/email/address values.
     *
     * @return array{groups: int, members: int}
     */
    public function assignGroups(bool $apply): array
    {
        $customers = Customer::withTestData()
            ->where('is_test_data', false)
            ->whereNull('merged_into_customer_id')
            ->orderBy('id')
            ->get();

        // Clear previous grouping for re-run (only when applying)
        if ($apply) {
            Customer::withTestData()
                ->where('is_test_data', false)
                ->whereNull('merged_into_customer_id')
                ->update([
                    'duplicate_group_id' => null,
                    'is_duplicate_primary' => true,
                ]);
            $customers = Customer::withTestData()
                ->where('is_test_data', false)
                ->whereNull('merged_into_customer_id')
                ->orderBy('id')
                ->get();
        }

        $groups = $this->buildGroups($customers);
        $memberCount = 0;

        foreach ($groups as $groupId => $members) {
            /** @var Collection<int, Customer> $members */
            if ($members->count() < 2) {
                continue;
            }
            $memberCount += $members->count();
            $primary = $this->recommendPrimary($members);
            if ($apply) {
                foreach ($members as $c) {
                    $c->forceFill([
                        'duplicate_group_id' => $groupId,
                        'is_duplicate_primary' => $c->id === $primary->id,
                    ])->saveQuietly();
                }
            }
        }

        return ['groups' => count(array_filter($groups, fn ($m) => $m->count() >= 2)), 'members' => $memberCount];
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<string, Collection<int, Customer>>
     */
    public function buildGroups(Collection $customers): array
    {
        $parent = [];
        foreach ($customers as $c) {
            $parent[$c->id] = $c->id;
        }

        $find = function (int $id) use (&$parent, &$find): int {
            if ($parent[$id] !== $id) {
                $parent[$id] = $find($parent[$id]);
            }

            return $parent[$id];
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $byPhone = [];
        $byEmail = [];
        foreach ($customers as $c) {
            $phoneKey = $this->validation->phoneMatchKey($c->phone) ?? $this->validation->phoneMatchKey($c->phone_normalized);
            if ($phoneKey) {
                if (isset($byPhone[$phoneKey])) {
                    $union($byPhone[$phoneKey], $c->id);
                } else {
                    $byPhone[$phoneKey] = $c->id;
                }
            }
            $email = strtolower(trim((string) $c->email));
            if ($email !== '' && $this->validation->isValidEmail($email)) {
                if (isset($byEmail[$email])) {
                    $union($byEmail[$email], $c->id);
                } else {
                    $byEmail[$email] = $c->id;
                }
            }
        }

        // Fuzzy name + address pairs (O(n^2) but customer dirs are modest)
        $list = $customers->values();
        $n = $list->count();
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->fuzzyNameAddressMatch($list[$i], $list[$j])) {
                    $union($list[$i]->id, $list[$j]->id);
                }
            }
        }

        $buckets = [];
        foreach ($customers as $c) {
            $root = $find($c->id);
            $buckets[$root] ??= collect();
            $buckets[$root]->push($c);
        }

        $groups = [];
        foreach ($buckets as $root => $members) {
            if ($members->count() < 2) {
                continue;
            }
            $groupId = 'dup_'.substr(sha1('customer-dup-'.$root.'-'.$members->pluck('id')->sort()->implode('-')), 0, 16);
            $groups[$groupId] = $members;
        }

        return $groups;
    }

    /**
     * @param  Collection<int, Customer>  $members
     */
    public function recommendPrimary(Collection $members): Customer
    {
        return $members->sortByDesc(fn (Customer $c) => $this->completenessScore($c))->first();
    }

    public function completenessScore(Customer $c): int
    {
        $score = 0;
        if ($this->validation->isValidPhone($c->phone)) {
            $score += 40;
        }
        if ($this->validation->isValidEmail($c->email)) {
            $score += 30;
        }
        if (! $this->validation->isInvalidName($c->name)) {
            $score += 20;
        }
        if (filled($c->address)) {
            $score += 5;
        }

        $userId = $c->user_id;
        if ($userId) {
            $score += min(30, Job::withTestData()->where('customer_id', $userId)->count() * 10);
            $score += min(20, Quote::withTestData()->where('customer_id', $userId)->count() * 5);
            $score += min(20, Invoice::withTestData()->where('customer_id', $userId)->count() * 5);
        }

        // Prefer older established records slightly
        $score += max(0, 10 - (int) $c->id % 10);

        return $score;
    }

    private function fuzzyNameAddressMatch(Customer $a, Customer $b): bool
    {
        $nameA = strtolower(trim((string) $a->name));
        $nameB = strtolower(trim((string) $b->name));
        $addrA = strtolower(trim((string) $a->address));
        $addrB = strtolower(trim((string) $b->address));

        if ($nameA === '' || $nameB === '' || $addrA === '' || $addrB === '') {
            return false;
        }
        if ($this->validation->isInvalidName($a->name) || $this->validation->isInvalidName($b->name)) {
            return false;
        }

        similar_text($nameA, $nameB, $namePct);
        if ($namePct < 85) {
            return false;
        }

        similar_text($addrA, $addrB, $addrPct);

        return $addrPct >= 80;
    }
}
