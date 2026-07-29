<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Payments\PaymentDestinationService;
use App\Services\Payments\StripePaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeConnectController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['contractor', 'pm', 'owner'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Owner may inspect another user
        if ($request->user_id && $user->role === 'owner') {
            $user = User::findOrFail((int) $request->user_id);
        }

        return response()->json($this->statusPayload($user));
    }

    public function start(Request $request, PaymentProviderInterface $payments): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role, ['contractor', 'pm'], true)) {
            return response()->json(['message' => 'Only contractors and PMs can connect Stripe payouts'], 403);
        }

        if (config('payment.provider') !== 'stripe' || ! $payments instanceof StripePaymentProvider) {
            return response()->json(['message' => 'Stripe Connect is not enabled (PAYMENT_PROVIDER must be stripe)'], 422);
        }

        $data = $request->validate([
            'return_url' => 'nullable|url',
            'refresh_url' => 'nullable|url',
        ]);

        try {
            $result = $payments->createAccountOnboardingLink(
                $user,
                $data['return_url'] ?? null,
                $data['refresh_url'] ?? null
            );
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'connect')) {
                return response()->json([
                    'message' => 'Stripe Connect is not activated on this Stripe account yet. Complete Connect signup in the Stripe Dashboard, then try again.',
                    'error' => 'connect_not_enabled',
                ], 422);
            }

            return response()->json(['message' => 'Unable to start Stripe Connect onboarding'], 422);
        }

        return response()->json([
            'message' => 'Stripe Connect onboarding link created',
            'account_id' => $this->maskAccountId($result['account_id'] ?? null),
            'stripe_account_ref' => $this->maskAccountId($result['account_id'] ?? null),
            'onboarding_url' => $result['onboarding_url'],
        ]);
    }

    public function refresh(Request $request, PaymentProviderInterface $payments): JsonResponse
    {
        return $this->start($request, $payments);
    }

    /**
     * Actively poll Stripe Accounts API and update local onboarding status.
     * Use after Connect return, or when account.updated webhooks were missed.
     */
    public function sync(Request $request, PaymentProviderInterface $payments): JsonResponse
    {
        $actor = $request->user();
        if (! in_array($actor->role, ['contractor', 'pm', 'owner'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $user = $actor;
        if ($request->user_id && $actor->role === 'owner') {
            $user = User::findOrFail((int) $request->user_id);
        }

        if (config('payment.provider') !== 'stripe' || ! $payments instanceof StripePaymentProvider) {
            return response()->json(['message' => 'Stripe Connect is not enabled'], 422);
        }

        if (! $user->stripe_account_id) {
            return response()->json(array_merge($this->statusPayload($user), [
                'message' => 'No Stripe Connect account linked yet',
            ]), 422);
        }

        try {
            $result = $payments->syncConnectedAccount($user);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to refresh Stripe account status',
                'error' => $e->getMessage(),
            ], 422);
        }

        $user->refresh();

        return response()->json(array_merge($this->statusPayload($user), [
            'message' => 'Stripe status refreshed',
            'charges_enabled' => $result['charges_enabled'],
            'payouts_enabled' => $result['payouts_enabled'],
            'details_submitted' => $result['details_submitted'],
        ]));
    }

    /**
     * PM-05 / A-04 — authoritative Connect status; never expose full account ID.
     *
     * @return array<string, mixed>
     */
    private function statusPayload(User $user): array
    {
        $provider = (string) config('payment.provider');
        $mode = app(PaymentDestinationService::class)->paymentModeLabel($provider);
        $hasAccount = filled($user->stripe_account_id);

        $rawRequirements = $user->stripe_requirements_due ?? [];
        if (is_string($rawRequirements)) {
            $decoded = json_decode($rawRequirements, true);
            $rawRequirements = is_array($decoded) ? $decoded : [];
        }

        return [
            'provider' => $provider,
            'mode' => $mode,
            'livemode' => $mode === 'LIVE',
            'has_stripe_account' => $hasAccount,
            // Masked reference only (last 4). Full ID never returned to clients.
            'stripe_account_ref' => $this->maskAccountId($user->stripe_account_id),
            'stripe_account_id' => null,
            'onboarding_status' => $user->stripe_onboarding_status,
            // CT-05: never expose raw Stripe requirement paths to contractors
            'requirements_due' => [],
            'requirements_plain' => $this->plainRequirements($rawRequirements),
            'payout_ready' => (bool) $user->stripe_payout_ready,
            'status_label' => $this->statusLabel($user),
            'support_guidance' => 'Finish setup in Stripe’s secure form. ServiceOP never stores your bank details. If stuck, contact support with your account ref only.',
            'publishable_key' => $provider === 'stripe'
                ? config('payment.stripe.publishable')
                : null,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>|array<int, string>  $requirements
     * @return list<string>
     */
    private function plainRequirements(array $requirements): array
    {
        $out = [];
        foreach ($requirements as $req) {
            $key = strtolower((string) $req);
            $out[] = match (true) {
                str_contains($key, 'individual.verification.document') || str_contains($key, 'id_number') => 'Verify your identity with a government ID in Stripe.',
                str_contains($key, 'external_account') || str_contains($key, 'bank') => 'Add a bank account for payouts in Stripe.',
                str_contains($key, 'tos_acceptance') => 'Accept Stripe’s terms of service.',
                str_contains($key, 'business_profile') => 'Complete your business profile in Stripe.',
                str_contains($key, 'individual.dob') || str_contains($key, 'address') => 'Confirm your personal details (address / date of birth) in Stripe.',
                str_contains($key, 'company') => 'Complete company information in Stripe.',
                default => 'Additional information is required in Stripe — continue setup to see the next step.',
            };
        }

        return array_values(array_unique($out));
    }

    private function statusLabel(User $user): string
    {
        if (! $user->stripe_account_id) {
            return 'Not connected';
        }
        if ($user->stripe_payout_ready) {
            return 'Ready for payouts';
        }

        return $user->stripe_onboarding_status
            ? 'Onboarding: '.$user->stripe_onboarding_status
            : 'Connected — setup incomplete';
    }

    private function maskAccountId(?string $accountId): ?string
    {
        if (! filled($accountId)) {
            return null;
        }
        $id = (string) $accountId;
        if (strlen($id) <= 4) {
            return $id;
        }

        return '…'.substr($id, -4);
    }
}
