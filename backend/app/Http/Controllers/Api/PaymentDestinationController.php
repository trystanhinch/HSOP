<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PaymentDestination;
use App\Services\Payments\PaymentDestinationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentDestinationController extends Controller
{
    public function __construct(private readonly PaymentDestinationService $destinations) {}

    public function index(Request $request): JsonResponse
    {
        $brandId = $request->query('brand_id') ? (int) $request->query('brand_id') : null;

        return response()->json([
            'payment_mode' => $this->destinations->paymentModeLabel(),
            'payment_provider' => config('payment.provider'),
            'brands' => Brand::query()->orderBy('id')->get(['id', 'slug', 'company_name', 'status']),
            'destinations' => $this->destinations->listForOwner($brandId),
            'note' => 'Customer payment destinations are separate from contractor payout (Stripe Connect) settings.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => 'required|integer|exists:brands,id',
            'payment_method' => 'required|in:stripe,e_transfer',
            'destination_value' => 'nullable|string|max:255',
            'destination_type' => 'nullable|in:company_verified,contractor',
            'is_verified' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'confirm_live_change' => 'nullable|boolean',
            'owner_override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:2000',
        ]);

        $dest = $this->destinations->upsert($data, $request->user());

        return response()->json([
            'message' => 'Payment destination saved.',
            'destination' => $dest->fresh(),
            'payment_mode' => $this->destinations->paymentModeLabel(),
        ], 201);
    }

    public function update(Request $request, PaymentDestination $paymentDestination): JsonResponse
    {
        $data = $request->validate([
            'destination_value' => 'nullable|string|max:255',
            'destination_type' => 'nullable|in:company_verified,contractor',
            'is_verified' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'confirm_live_change' => 'nullable|boolean',
            'owner_override' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:2000',
            'reason' => 'nullable|string|max:2000',
        ]);

        $data['brand_id'] = $paymentDestination->brand_id;
        $data['payment_method'] = $paymentDestination->payment_method;
        if (! array_key_exists('destination_value', $data) || $data['destination_value'] === null) {
            $data['destination_value'] = $paymentDestination->destination_value;
        }

        $dest = $this->destinations->upsert($data, $request->user());

        return response()->json([
            'message' => 'Payment destination updated.',
            'destination' => $dest,
            'payment_mode' => $this->destinations->paymentModeLabel(),
        ]);
    }
}
