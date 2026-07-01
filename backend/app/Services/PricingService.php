<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Setting;

class PricingService
{
    public function markupDivisor(): float
    {
        $contractorPct = (float) (Setting::where('key', 'split_contractor_pct')->value('value') ?? 80);

        return $contractorPct > 0 ? $contractorPct / 100 : 0.80;
    }

    public function gstRate(): float
    {
        $value = Setting::where('key', 'gst_rate')->value('value');

        return (float) ($value ?: 5);
    }

    public function splitFromJob(?Job $job): array
    {
        if ($job) {
            return [
                'contractor_pct' => (float) ($job->split_contractor_pct ?? 80),
                'pm_pct' => (float) ($job->split_pm_pct ?? 10),
                'company_pct' => (float) ($job->split_company_pct ?? 10),
            ];
        }

        return [
            'contractor_pct' => (float) (Setting::where('key', 'split_contractor_pct')->value('value') ?? 80),
            'pm_pct' => (float) (Setting::where('key', 'split_pm_pct')->value('value') ?? 10),
            'company_pct' => (float) (Setting::where('key', 'split_company_pct')->value('value') ?? 10),
        ];
    }

    public function seedSplitOntoJob(Job $job): Job
    {
        $split = $this->splitFromJob(null);
        $job->update([
            'split_contractor_pct' => $split['contractor_pct'],
            'split_pm_pct' => $split['pm_pct'],
            'split_company_pct' => $split['company_pct'],
        ]);

        return $job->fresh();
    }

    /** Customer subtotal before GST = contractor price / (contractor_pct / 100) */
    public function customerSubtotalFromContractor(float $contractorPrice, ?float $contractorPct = null): float
    {
        if ($contractorPrice <= 0) {
            return 0;
        }

        $pct = $contractorPct ?? ((float) (Setting::where('key', 'split_contractor_pct')->value('value') ?? 80));
        $divisor = $pct / 100;

        return round($contractorPrice / ($divisor > 0 ? $divisor : 0.80), 2);
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

    public function fromContractorPrice(float $contractorPrice, bool $gstEnabled = true, ?Job $job = null): array
    {
        $split = $this->splitFromJob($job);
        $contractorPct = $split['contractor_pct'];
        $pmPct = $split['pm_pct'];
        $companyPct = $split['company_pct'];

        $subtotal = $this->customerSubtotalFromContractor($contractorPrice, $contractorPct);
        $pmAmount = round($subtotal * ($pmPct / 100), 2);
        $companyAmount = round($subtotal * ($companyPct / 100), 2);
        $totals = $this->calculateTotals($subtotal, $gstEnabled);

        return array_merge($totals, [
            'contractor_base_price' => $contractorPrice,
            'contractor_pct' => $contractorPct,
            'pm_pct' => $pmPct,
            'company_pct' => $companyPct,
            'pm_amount' => $pmAmount,
            'company_amount' => $companyAmount,
            'hsop_markup' => $pmAmount + $companyAmount,
        ]);
    }
}
