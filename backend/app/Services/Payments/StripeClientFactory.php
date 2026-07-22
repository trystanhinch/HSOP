<?php

namespace App\Services\Payments;

use App\Models\User;
use Stripe\StripeClient;

class StripeClientFactory
{
    public function make(): StripeClient
    {
        $secret = config('payment.stripe.secret');
        if (! $secret) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        return new StripeClient($secret);
    }

    public function publishableKey(): ?string
    {
        return config('payment.stripe.publishable') ?: null;
    }

    public function webhookSecret(): ?string
    {
        return config('payment.stripe.webhook_secret') ?: null;
    }

    /**
     * Resolve the payee user for a payout split (contractor or PM).
     */
    public static function payeeForPayout(\App\Models\Payout $payout): ?User
    {
        $split = $payout->split_type ?: $payout->payout_type;
        if ($split === 'contractor') {
            return $payout->contractor_id ? User::find($payout->contractor_id) : null;
        }
        if ($split === 'pm') {
            return $payout->pm_id
                ? User::find($payout->pm_id)
                : ($payout->contractor_id ? User::find($payout->contractor_id) : null);
        }

        return null;
    }
}
