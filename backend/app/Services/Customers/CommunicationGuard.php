<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Blocks outbound SMS/email when customer has do_not_contact or incompatible preference.
 */
class CommunicationGuard
{
    public function __construct(
        private CustomerValidationService $validation,
    ) {}

    /**
     * @return array{blocked: bool, reason: ?string, channel: ?string}
     */
    public function checkSms(?int $userId = null, ?string $phone = null): array
    {
        $customer = $this->resolveCustomer($userId, null, $phone);
        if (! $customer) {
            return ['blocked' => false, 'reason' => null, 'channel' => null];
        }
        if ($customer->do_not_contact) {
            return $this->blocked('do_not_contact', 'sms', $customer->id);
        }
        $pref = $customer->communication_preference ?? 'both';
        if (in_array($pref, ['email', 'none'], true)) {
            return $this->blocked('communication_preference_blocks_sms', 'sms', $customer->id);
        }

        return ['blocked' => false, 'reason' => null, 'channel' => null];
    }

    /**
     * @return array{blocked: bool, reason: ?string, channel: ?string}
     */
    public function checkEmail(?int $userId = null, ?string $email = null): array
    {
        $customer = $this->resolveCustomer($userId, $email, null);
        if (! $customer) {
            return ['blocked' => false, 'reason' => null, 'channel' => null];
        }
        if ($customer->do_not_contact) {
            return $this->blocked('do_not_contact', 'email', $customer->id);
        }
        $pref = $customer->communication_preference ?? 'both';
        if (in_array($pref, ['sms', 'none'], true)) {
            return $this->blocked('communication_preference_blocks_email', 'email', $customer->id);
        }

        return ['blocked' => false, 'reason' => null, 'channel' => null];
    }

    private function resolveCustomer(?int $userId, ?string $email, ?string $phone): ?Customer
    {
        if ($userId) {
            $byUser = Customer::withTestData()->where('user_id', $userId)->whereNull('merged_into_customer_id')->first();
            if ($byUser) {
                return $byUser;
            }
        }
        if ($email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $byEmail = Customer::withTestData()->whereRaw('LOWER(email) = ?', [$email])->whereNull('merged_into_customer_id')->first();
                if ($byEmail) {
                    return $byEmail;
                }
            }
        }
        if ($phone) {
            $key = $this->validation->phoneMatchKey($phone);
            if ($key) {
                return Customer::withTestData()
                    ->whereNull('merged_into_customer_id')
                    ->where(function ($q) use ($key) {
                        $q->where('phone_normalized', 'like', '%'.$key)
                            ->orWhereRaw(
                                "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),'-',''),' ',''),'(',''), 10) = ?",
                                [$key]
                            );
                    })
                    ->first();
            }
        }

        return null;
    }

    /**
     * @return array{blocked: bool, reason: string, channel: string}
     */
    private function blocked(string $reason, string $channel, int $customerId): array
    {
        Log::info('Outbound notification blocked — communication preference', [
            'reason' => $reason,
            'channel' => $channel,
            'customer_id' => $customerId,
        ]);

        return ['blocked' => true, 'reason' => $reason, 'channel' => $channel];
    }
}
