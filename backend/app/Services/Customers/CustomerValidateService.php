<?php

namespace App\Services\Customers;

use App\Models\Customer;

class CustomerValidateService
{
    public function __construct(
        private CustomerValidationService $validation,
        private CustomerDuplicateDetector $duplicates,
    ) {}

    /**
     * @return array{
     *   dry_run: bool,
     *   scanned: int,
     *   flagged_quality: int,
     *   flags_by_reason: array<string, int>,
     *   duplicate_groups: int,
     *   duplicate_members: int,
     *   skipped_test_data: int
     * }
     */
    public function run(bool $apply = false): array
    {
        $flagsByReason = [];
        $flagged = 0;
        $skippedTest = 0;
        $scanned = 0;

        Customer::withTestData()
            ->whereNull('merged_into_customer_id')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($apply, &$flagsByReason, &$flagged, &$skippedTest, &$scanned) {
                foreach ($chunk as $customer) {
                    $scanned++;
                    if ($customer->isTestData()) {
                        $skippedTest++;

                        continue;
                    }

                    $flags = $this->validation->evaluateFlags(
                        $customer->name,
                        $customer->phone,
                        $customer->email,
                        $customer->address,
                    );

                    foreach ($flags as $flag) {
                        $flagsByReason[$flag] = ($flagsByReason[$flag] ?? 0) + 1;
                    }
                    if ($flags !== []) {
                        $flagged++;
                    }

                    if ($apply) {
                        $customer->forceFill([
                            'data_quality_flags' => $flags === [] ? null : $flags,
                            'phone_normalized' => $this->validation->normalizePhoneE164($customer->phone),
                        ])->saveQuietly();
                    }
                }
            });

        $dup = $this->duplicates->assignGroups($apply);

        return [
            'dry_run' => ! $apply,
            'scanned' => $scanned,
            'flagged_quality' => $flagged,
            'flags_by_reason' => $flagsByReason,
            'duplicate_groups' => $dup['groups'],
            'duplicate_members' => $dup['members'],
            'skipped_test_data' => $skippedTest,
        ];
    }
}
