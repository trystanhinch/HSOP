<?php

namespace App\Services;

use App\Models\Setting;

class PricingService
{
    public function markupDivisor(): float
    {
        $value = Setting::where('key', 'markup_divisor')->value('value');

        return (float) ($value ?: 0.80);
    }

    public function gstRate(): float
    {
        $value = Setting::where('key', 'gst_rate')->value('value');

        return (float) ($value ?: 5);
    }

    /** Customer subtotal before GST = contractor price / 0.80 */
    public function customerSubtotalFromContractor(float $contractorPrice): float
    {
        if ($contractorPrice <= 0) {
            return 0;
        }

        return round($contractorPrice / $this->markupDivisor(), 2);
    }

    public function platformMarkup(float $contractorPrice, float $customerSubtotal): float
    {
        return round(max(0, $customerSubtotal - $contractorPrice), 2);
    }

    public function calculateTotals(float $customerSubtotal, bool $gstEnabled = true, ?float $gstRate = null): array
    {
        $rate = $gstRate ?? $this->gstRate();
        $gst = $gstEnabled ? round($customerSubtotal * ($rate / 100), 2) : 0;
        $total = round($customerSubtotal + $gst, 2);

        return [
            'customer_subtotal' => $customerSubtotal,
            'gst_rate' => $rate,
            'gst' => $gst,
            'customer_total' => $total,
        ];
    }

    public function fromContractorPrice(float $contractorPrice, bool $gstEnabled = true): array
    {
        $subtotal = $this->customerSubtotalFromContractor($contractorPrice);
        $totals = $this->calculateTotals($subtotal, $gstEnabled);

        return array_merge($totals, [
            'contractor_base_price' => $contractorPrice,
            'hsop_markup' => $this->platformMarkup($contractorPrice, $subtotal),
        ]);
    }
}
