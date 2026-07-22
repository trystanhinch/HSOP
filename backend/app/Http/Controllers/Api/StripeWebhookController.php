<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\StripePaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePaymentProvider $stripe): JsonResponse
    {
        $payload = $request->getContent();
        $sig = $request->header('Stripe-Signature', '');

        try {
            $event = $stripe->constructEvent($payload, $sig);
        } catch (UnexpectedValueException $e) {
            Log::warning('Stripe webhook invalid payload', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed');

            return response()->json(['message' => 'Invalid signature'], 400);
        } catch (\RuntimeException $e) {
            Log::error('Stripe webhook misconfigured', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        try {
            $result = $stripe->handleWebhook($event->toArray());
        } catch (\Throwable $e) {
            // Return 500 so Stripe retries; event row may already mark failure.
            return response()->json(['message' => 'Processing error'], 500);
        }

        return response()->json([
            'received' => true,
            'handled' => $result['handled'] ?? false,
            'status' => $result['status'] ?? null,
        ]);
    }
}
