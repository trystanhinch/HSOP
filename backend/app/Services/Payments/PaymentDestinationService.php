<?php

namespace App\Services\Payments;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Job;
use App\Models\PaymentDestination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Audit A-03 — authoritative customer payment destination resolver.
 *
 * Decisions locked for this batch:
 * - Stripe destination_value = "platform" (1A); single platform Stripe account.
 * - Brand = job→lead→brand_id else primary/default brand (2A).
 * - Legacy settings migrated as needs_owner_review; settings keys not nulled (3A).
 *
 * Contractor payout destinations (Stripe Connect / A-04) are never used here.
 */
class PaymentDestinationService
{
    public const CONTRACTOR_EMAIL_BLOCK_MESSAGE = 'This email belongs to a contractor account and cannot be set as a company payment destination';

    /**
     * Resolve brand for a job (2A).
     */
    public function resolveBrandForJob(?Job $job): ?Brand
    {
        if ($job) {
            $job->loadMissing('lead');
            if ($job->lead?->brand_id) {
                $brand = Brand::query()->find($job->lead->brand_id);
                if ($brand) {
                    return $brand;
                }
            }
        }

        return $this->primaryBrand();
    }

    public function primaryBrand(): ?Brand
    {
        return Brand::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->first()
            ?? Brand::query()->orderBy('id')->first();
    }

    /**
     * Customer-facing payment payload for portal / payment page / invoices.
     *
     * @return array<string, mixed>
     */
    public function customerFacingForJob(?Job $job): array
    {
        $brand = $this->resolveBrandForJob($job);

        return $this->customerFacingForBrand($brand);
    }

    /**
     * @return array<string, mixed>
     */
    public function customerFacingForBrand(?Brand $brand): array
    {
        $provider = config('payment.provider');
        $mode = $this->paymentModeLabel($provider);

        if (! $brand) {
            return [
                'brand_id' => null,
                'brand_name' => null,
                'payment_mode' => $mode,
                'payment_provider' => $provider,
                'default_method' => 'stripe',
                'company_email' => null,
                'payment_instructions' => null,
                'e_transfer' => null,
                'stripe' => null,
                'card_payments_enabled' => false,
                'destination_configured' => false,
                'needs_owner_review' => true,
                'message' => 'Payment destination not configured. Contact the company.',
            ];
        }

        $stripe = PaymentDestination::query()
            ->where('brand_id', $brand->id)
            ->where('payment_method', PaymentDestination::METHOD_STRIPE)
            ->where('is_active', true)
            ->first();

        $eTransfer = PaymentDestination::query()
            ->where('brand_id', $brand->id)
            ->where('payment_method', PaymentDestination::METHOD_E_TRANSFER)
            ->where('is_active', true)
            ->first();

        $stripeReady = $stripe && $stripe->isCustomerFacing() && $provider === 'stripe';
        $eTransferReady = $eTransfer && $eTransfer->isCustomerFacing() && filled($eTransfer->destination_value);

        $instructions = null;
        if ($eTransferReady) {
            $instructions = 'Send e-transfer to '.$eTransfer->destination_value;
        } elseif ($eTransfer && $eTransfer->needs_owner_review) {
            $instructions = null; // do not expose unverified / contractor-flagged destinations
        }

        return [
            'brand_id' => $brand->id,
            'brand_name' => $brand->company_name,
            'payment_mode' => $mode,
            'payment_provider' => $provider,
            'default_method' => 'stripe',
            // Backward-compatible keys used by PaymentPage / CustomerPortal
            'company_email' => $eTransferReady ? $eTransfer->destination_value : null,
            'payment_instructions' => $instructions,
            'e_transfer' => $eTransfer ? $this->serializeDestination($eTransfer, $eTransferReady) : null,
            'stripe' => $stripe ? $this->serializeDestination($stripe, $stripeReady) : null,
            'card_payments_enabled' => $stripeReady,
            'destination_configured' => $stripeReady || $eTransferReady,
            'needs_owner_review' => (bool) (($stripe?->needs_owner_review) || ($eTransfer?->needs_owner_review)),
            // Publishable key is public; always expose when Stripe provider is active (never the secret).
            'stripe_publishable_key' => $provider === 'stripe' ? config('payment.stripe.publishable') : null,
            'message' => ($stripeReady || $eTransferReady)
                ? null
                : 'Verified payment destination pending owner review.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOwner(?int $brandId = null): array
    {
        $q = PaymentDestination::query()->with('brand:id,slug,company_name')->orderBy('brand_id')->orderBy('payment_method');
        if ($brandId) {
            $q->where('brand_id', $brandId);
        }

        return $q->get()->map(fn (PaymentDestination $d) => $this->serializeDestination($d, $d->isCustomerFacing()) + [
            'legacy_source_note' => $d->legacy_source_note,
            'override_reason' => $d->override_reason,
            'contractor_email_override' => $d->contractor_email_override,
            'meta' => $d->meta,
            'brand' => $d->brand ? [
                'id' => $d->brand->id,
                'slug' => $d->brand->slug,
                'company_name' => $d->brand->company_name,
            ] : null,
            'legacy_settings' => [
                'company_email' => \App\Models\Setting::where('key', 'company_email')->value('value'),
                'payment_instructions' => \App\Models\Setting::where('key', 'payment_instructions')->value('value'),
            ],
            'blocked_if_resaved' => $d->payment_method === PaymentDestination::METHOD_E_TRANSFER
                && $this->matchesContractorEmail((string) $d->destination_value)
                && ! $d->contractor_email_override,
        ])->all();
    }

    /**
     * Create or update a destination. Blocks contractor emails unless override+reason.
     *
     * @param  array{
     *   brand_id: int,
     *   payment_method: string,
     *   destination_value?: string|null,
     *   destination_type?: string,
     *   is_verified?: bool,
     *   is_active?: bool,
     *   confirm_live_change?: bool,
     *   owner_override?: bool,
     *   override_reason?: string|null,
     *   reason?: string|null,
     * }  $data
     */
    public function upsert(array $data, User $actor): PaymentDestination
    {
        if ($actor->role !== 'owner') {
            abort(403, 'Only owners can edit customer payment destinations.');
        }

        $method = $data['payment_method'];
        $brandId = (int) $data['brand_id'];
        $value = isset($data['destination_value']) ? trim((string) $data['destination_value']) : null;
        if ($value === '') {
            $value = null;
        }

        if ($method === PaymentDestination::METHOD_STRIPE) {
            $value = $value ?: 'platform';
        }

        if ($method === PaymentDestination::METHOD_E_TRANSFER) {
            if (! $value || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'destination_value' => 'A valid e-transfer email is required.',
                ]);
            }
            $value = strtolower($value);
        }

        $destinationType = $data['destination_type'] ?? PaymentDestination::TYPE_COMPANY_VERIFIED;
        if ($destinationType === PaymentDestination::TYPE_CONTRACTOR) {
            throw ValidationException::withMessages([
                'destination_type' => 'Contractor destinations cannot be used for customer payments. Use company_verified.',
            ]);
        }

        $ownerOverride = (bool) ($data['owner_override'] ?? false);
        $overrideReason = trim((string) ($data['override_reason'] ?? $data['reason'] ?? ''));
        $matchesContractor = $method === PaymentDestination::METHOD_E_TRANSFER && $this->matchesContractorEmail((string) $value);

        if ($matchesContractor && ! $ownerOverride) {
            throw ValidationException::withMessages([
                'destination_value' => self::CONTRACTOR_EMAIL_BLOCK_MESSAGE,
                'requires_owner_override' => true,
            ]);
        }

        if ($matchesContractor && $ownerOverride && $overrideReason === '') {
            throw ValidationException::withMessages([
                'override_reason' => 'A reason is required to override the contractor-email block.',
            ]);
        }

        $existing = PaymentDestination::query()
            ->where('brand_id', $brandId)
            ->where('payment_method', $method)
            ->first();

        $changingLive = $existing
            && $existing->is_active
            && $existing->is_verified
            && (string) $existing->destination_value !== (string) $value;

        if ($changingLive && empty($data['confirm_live_change'])) {
            throw ValidationException::withMessages([
                'confirm_live_change' => 'Changing a live payment destination requires confirmation (current vs new value).',
                'current_value' => $existing->destination_value,
                'new_value' => $value,
            ]);
        }

        return DB::transaction(function () use (
            $existing, $brandId, $method, $value, $destinationType, $data, $actor,
            $matchesContractor, $ownerOverride, $overrideReason, $changingLive
        ) {
            $before = $existing?->toArray();

            $attrs = [
                'brand_id' => $brandId,
                'payment_method' => $method,
                'destination_type' => $destinationType,
                'destination_value' => $value,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'updated_by' => $actor->id,
                'contractor_email_override' => $matchesContractor && $ownerOverride,
                'override_reason' => $matchesContractor && $ownerOverride ? $overrideReason : ($existing?->override_reason),
                'needs_owner_review' => false,
            ];

            $verify = array_key_exists('is_verified', $data) ? (bool) $data['is_verified'] : true;
            $attrs['is_verified'] = $verify;
            if ($verify) {
                $attrs['verified_by'] = $actor->id;
                $attrs['verified_at'] = now();
            }

            if ($existing) {
                $existing->fill($attrs)->save();
                $dest = $existing->fresh();
            } else {
                $dest = PaymentDestination::create($attrs);
            }

            $action = $changingLive
                ? 'payment_destination_live_changed'
                : ($before ? 'payment_destination_updated' : 'payment_destination_created');

            if ($matchesContractor && $ownerOverride) {
                $action = 'payment_destination_contractor_override';
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'user_role' => $actor->role,
                'object_type' => 'payment_destination',
                'object_id' => $dest->id,
                'action_type' => $action,
                'previous_value' => $before,
                'new_value' => $dest->toArray(),
                'reason' => $overrideReason !== '' ? $overrideReason : ($data['reason'] ?? null),
                'created_at' => now(),
            ]);

            return $dest;
        });
    }

    public function matchesContractorEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (User::withTestData()->where('role', 'contractor')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return true;
        }

        return DB::table('contractors')->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    public function paymentModeLabel(?string $provider = null): string
    {
        $provider = $provider ?? config('payment.provider');
        if ($provider === 'stripe') {
            $secret = (string) config('payment.stripe.secret');
            if (str_starts_with($secret, 'sk_live')) {
                return 'LIVE';
            }
            if (str_starts_with($secret, 'sk_test') || $secret === '') {
                return 'TEST';
            }

            return 'LIVE';
        }

        return 'TEST';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDestination(PaymentDestination $d, bool $customerReady): array
    {
        return [
            'id' => $d->id,
            'brand_id' => $d->brand_id,
            'payment_method' => $d->payment_method,
            'destination_type' => $d->destination_type,
            'destination_value' => $d->displayValue(),
            'is_verified' => $d->is_verified,
            'needs_owner_review' => $d->needs_owner_review,
            'is_active' => $d->is_active,
            'customer_ready' => $customerReady,
            'verified_at' => $d->verified_at?->toIso8601String(),
            'verified_by' => $d->verified_by,
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
