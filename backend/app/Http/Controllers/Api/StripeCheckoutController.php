<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentProviderInterface;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeCheckoutController extends Controller
{
    /**
     * Create (or reuse) a Stripe Checkout session for a portal invoice payment.
     */
    public function portalCheckout(Request $request, string $token, PaymentProviderInterface $payments): JsonResponse
    {
        $lead = Lead::where('customer_portal_token', $token)->firstOrFail();
        $job = Job::with(['invoice', 'lead'])->where('lead_id', $lead->id)->firstOrFail();

        if (! in_array($job->status, ['payment_pending', 'etransfer_pending_confirmation', 'completion_accepted'], true)
            && ! in_array($job->invoice?->status, ['awaiting_payment', 'invoice_sent', 'sent', 'payment_failed', 'partially_paid', 'draft'], true)) {
            return response()->json(['message' => 'Job is not awaiting payment'], 422);
        }

        $invoice = $job->invoice;
        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice already paid', 'invoice' => $invoice], 422);
        }

        if (config('payment.provider') !== 'stripe') {
            return response()->json([
                'message' => 'Card checkout requires PAYMENT_PROVIDER=stripe',
                'provider' => config('payment.provider'),
            ], 422);
        }

        $link = $payments->createPaymentLink($invoice->fresh(['customer', 'job.lead']));

        return response()->json([
            'provider' => $link['provider'],
            'checkout_url' => $link['payment_link'],
            'session_id' => $link['provider_reference'],
            'publishable_key' => config('payment.stripe.publishable'),
        ]);
    }

    public function jobCheckout(Request $request, Job $job, PaymentProviderInterface $payments): JsonResponse
    {
        $user = $request->user();
        if ($user->role === 'customer' && (int) $job->customer_id !== (int) $user->id) {
            abort(403);
        }
        if (! in_array($user->role, ['customer', 'owner', 'pm'], true)) {
            abort(403);
        }

        $job->loadMissing(['invoice', 'lead']);
        $invoice = $job->invoice;
        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice already paid'], 422);
        }
        if (config('payment.provider') !== 'stripe') {
            return response()->json(['message' => 'Card checkout requires PAYMENT_PROVIDER=stripe'], 422);
        }

        $link = $payments->createPaymentLink($invoice->fresh(['customer', 'job.lead']));

        return response()->json([
            'provider' => $link['provider'],
            'checkout_url' => $link['payment_link'],
            'session_id' => $link['provider_reference'],
            'publishable_key' => config('payment.stripe.publishable'),
        ]);
    }
}
