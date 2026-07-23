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

    public function connectWebhookSecret(): ?string
    {
        return config('payment.stripe.connect_webhook_secret') ?: null;
    }

    /**
     * Platform + Connect webhook signing secrets (deduped, non-empty).
     *
     * @return list<string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];
        foreach ([$this->webhookSecret(), $this->connectWebhookSecret()] as $secret) {
            if (is_string($secret) && $secret !== '' && ! in_array($secret, $secrets, true)) {
                $secrets[] = $secret;
            }
        }

        return $secrets;
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
