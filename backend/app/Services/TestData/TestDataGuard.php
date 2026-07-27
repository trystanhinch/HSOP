<?php

namespace App\Services\TestData;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Blocks outbound SMS/email when the recipient or related job/lead is test data.
 */
class TestDataGuard
{
    /**
     * @return array{blocked: bool, reason: ?string}
     */
    public function checkOutbound(?int $userId = null, ?int $jobId = null, ?string $email = null, ?string $phone = null): array
    {
        if ($userId) {
            $user = User::withTestData()->find($userId);
            if ($user?->isTestData()) {
                return $this->blocked('user_is_test_data', ['user_id' => $userId]);
            }
            $user?->loadMissing(['customer', 'contractor']);
            if ($user?->customer?->isTestData()) {
                return $this->blocked('customer_is_test_data', ['user_id' => $userId, 'customer_id' => $user->customer->id]);
            }
            if ($user?->contractor && method_exists($user->contractor, 'isTestData') && $user->contractor->isTestData()) {
                return $this->blocked('contractor_is_test_data', ['user_id' => $userId]);
            }
        }

        if ($jobId) {
            $job = Job::withTestData()->find($jobId);
            if ($job?->isTestData()) {
                return $this->blocked('job_is_test_data', ['job_id' => $jobId]);
            }
            if ($job?->lead_id) {
                $lead = Lead::withTestData()->find($job->lead_id);
                if ($lead?->isTestData()) {
                    return $this->blocked('lead_is_test_data', ['job_id' => $jobId, 'lead_id' => $lead->id]);
                }
            }
            if ($job?->customer_id) {
                $customerUser = User::withTestData()->find($job->customer_id);
                if ($customerUser?->isTestData()) {
                    return $this->blocked('job_customer_is_test_data', ['job_id' => $jobId]);
                }
            }
        }

        if ($email) {
            $email = strtolower(trim($email));
            $user = User::withTestData()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user?->isTestData()) {
                return $this->blocked('email_matches_test_user', ['email' => $email]);
            }
            $customer = Customer::withTestData()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($customer?->isTestData()) {
                return $this->blocked('email_matches_test_customer', ['email' => $email]);
            }
            $lead = Lead::withTestData()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($lead?->isTestData()) {
                return $this->blocked('email_matches_test_lead', ['email' => $email]);
            }
        }

        if ($phone) {
            $digits = preg_replace('/\D+/', '', $phone) ?: '';
            if (strlen($digits) >= 10) {
                $tail = substr($digits, -10);
                $lead = Lead::onlyTestData()
                    ->whereNotNull('phone')
                    ->get()
                    ->first(function (Lead $l) use ($tail) {
                        $d = preg_replace('/\D+/', '', (string) $l->phone) ?: '';

                        return strlen($d) >= 10 && substr($d, -10) === $tail;
                    });
                if ($lead) {
                    return $this->blocked('phone_matches_test_lead', ['lead_id' => $lead->id]);
                }
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{blocked: bool, reason: string}
     */
    private function blocked(string $reason, array $context = []): array
    {
        Log::info('Outbound notification blocked — test data', array_merge(['reason' => $reason], $context));

        return ['blocked' => true, 'reason' => $reason];
    }
}
