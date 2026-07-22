<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\User;
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

        return response()->json([
            'provider' => config('payment.provider'),
            'stripe_account_id' => $user->stripe_account_id,
            'onboarding_status' => $user->stripe_onboarding_status,
            'requirements_due' => $user->stripe_requirements_due,
            'payout_ready' => (bool) $user->stripe_payout_ready,
            // Never expose secret keys — publishable only when useful for Elements (Checkout uses hosted page)
            'publishable_key' => config('payment.provider') === 'stripe'
                ? config('payment.stripe.publishable')
                : null,
        ]);
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
            'account_id' => $result['account_id'],
            'onboarding_url' => $result['onboarding_url'],
        ]);
    }

    public function refresh(Request $request, PaymentProviderInterface $payments): JsonResponse
    {
        return $this->start($request, $payments);
    }
}
