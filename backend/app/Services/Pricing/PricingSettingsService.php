<?php

namespace App\Services\Pricing;

use App\Models\PricingSettingVersion;
use App\Models\Setting;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A-20 — pricing calculator + validated defaults with version history.
 * Job snapshots remain authoritative for existing work (PricingService::splitFromJob).
 */
class PricingSettingsService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * Live calculator preview (does not persist).
     *
     * @param  array{
     *   contractor_price?: float|int|string,
     *   gst_rate?: float|int|string|null,
     *   split_contractor_pct?: float|int|string|null,
     *   split_pm_pct?: float|int|string|null,
     *   split_company_pct?: float|int|string|null,
     * }  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $contractorPrice = (float) ($input['contractor_price'] ?? 800);
        if ($contractorPrice <= 0) {
            throw ValidationException::withMessages([
                'contractor_price' => 'Contractor price must be greater than zero.',
            ]);
        }

        $contractorPct = (float) ($input['split_contractor_pct'] ?? Setting::get('split_contractor_pct', 80));
        $pmPct = (float) ($input['split_pm_pct'] ?? Setting::get('split_pm_pct', 10));
        $companyPct = (float) ($input['split_company_pct'] ?? Setting::get('split_company_pct', 10));
        $gstRate = (float) ($input['gst_rate'] ?? Setting::get('gst_rate', 5));

        $this->assertSplitValid($contractorPct, $pmPct, $companyPct);
        $this->assertRatesSane($gstRate, $contractorPct);

        $subtotal = $this->pricing->customerSubtotalFromContractor($contractorPrice, $contractorPct);
        $pmAmount = round($subtotal * ($pmPct / 100), 2);
        $companyAmount = round($subtotal * ($companyPct / 100), 2);
        $totals = $this->pricing->calculateTotals($subtotal, true, $gstRate);
        $divisor = $contractorPct / 100;

        return [
            'contractor_price' => round($contractorPrice, 2),
            'markup_divisor' => round($divisor, 4),
            'customer_subtotal' => $totals['customer_subtotal'],
            'gst_rate' => $totals['gst_rate'],
            'gst' => $totals['gst'],
            'gst_label' => 'GST (tax) — separate from company margin',
            'customer_total' => $totals['customer_total'],
            'split_contractor_pct' => $contractorPct,
            'split_pm_pct' => $pmPct,
            'split_company_pct' => $companyPct,
            'contractor_share' => round($contractorPrice, 2),
            'pm_share' => $pmAmount,
            'company_share' => $companyAmount,
            'company_margin' => $companyAmount,
            'hsop_markup' => round($pmAmount + $companyAmount, 2),
            'note' => 'Existing jobs keep their saved split unless an authorized per-job override is recorded.',
        ];
    }

    /**
     * Persist global (or brand-scoped) pricing defaults with confirmation + version row.
     *
     * @param  array<string, mixed>  $data
     * @return array{settings: array<string, string>, version: PricingSettingVersion, preview: array<string, mixed>}
     */
    public function saveDefaults(array $data, User $actor, bool $confirmed, ?int $brandId = null): array
    {
        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_pricing_change' => 'Confirm that these settings only affect future quotes/jobs. Existing jobs keep their saved split.',
            ]);
        }

        $gstRate = (float) ($data['gst_rate'] ?? Setting::get('gst_rate', 5));
        $contractorPct = (float) ($data['split_contractor_pct'] ?? Setting::get('split_contractor_pct', 80));
        $pmPct = (float) ($data['split_pm_pct'] ?? Setting::get('split_pm_pct', 10));
        $companyPct = (float) ($data['split_company_pct'] ?? Setting::get('split_company_pct', 10));

        // Keep markup_divisor in sync with contractor share (authoritative path).
        if (array_key_exists('markup_divisor', $data) && $data['markup_divisor'] !== null
            && ! array_key_exists('split_contractor_pct', $data)) {
            $divisor = (float) $data['markup_divisor'];
            if ($divisor < 0.01 || $divisor > 0.99) {
                throw ValidationException::withMessages([
                    'markup_divisor' => 'Markup divisor must be between 0.01 and 0.99.',
                ]);
            }
            $contractorPct = round($divisor * 100, 4);
        }

        $this->assertSplitValid($contractorPct, $pmPct, $companyPct);
        $this->assertRatesSane($gstRate, $contractorPct);

        $previous = [
            'gst_rate' => Setting::get('gst_rate', '5'),
            'markup_divisor' => Setting::get('markup_divisor', '0.80'),
            'split_contractor_pct' => Setting::get('split_contractor_pct', '80'),
            'split_pm_pct' => Setting::get('split_pm_pct', '10'),
            'split_company_pct' => Setting::get('split_company_pct', '10'),
        ];

        $divisor = round($contractorPct / 100, 4);

        $version = DB::transaction(function () use (
            $gstRate, $contractorPct, $pmPct, $companyPct, $divisor, $previous, $actor, $brandId, $data
        ) {
            Setting::set('gst_rate', (string) $gstRate);
            Setting::set('markup_divisor', (string) $divisor);
            Setting::set('split_contractor_pct', (string) $contractorPct);
            Setting::set('split_pm_pct', (string) $pmPct);
            Setting::set('split_company_pct', (string) $companyPct);

            return PricingSettingVersion::create([
                'brand_id' => $brandId,
                'effective_date' => $data['effective_date'] ?? now()->toDateString(),
                'gst_rate' => $gstRate,
                'markup_divisor' => $divisor,
                'split_contractor_pct' => $contractorPct,
                'split_pm_pct' => $pmPct,
                'split_company_pct' => $companyPct,
                'created_by' => $actor->id,
                'previous_values' => $previous,
                'change_reason' => $data['change_reason'] ?? 'Owner updated GST/markup/split defaults',
            ]);
        });

        return [
            'settings' => [
                'gst_rate' => (string) $gstRate,
                'markup_divisor' => (string) $divisor,
                'split_contractor_pct' => (string) $contractorPct,
                'split_pm_pct' => (string) $pmPct,
                'split_company_pct' => (string) $companyPct,
            ],
            'version' => $version,
            'preview' => $this->preview([
                'contractor_price' => $data['example_contractor_price'] ?? 800,
                'gst_rate' => $gstRate,
                'split_contractor_pct' => $contractorPct,
                'split_pm_pct' => $pmPct,
                'split_company_pct' => $companyPct,
            ]),
        ];
    }

    public function assertSplitValid(float $contractor, float $pm, float $company): void
    {
        if ($contractor <= 0 || $contractor >= 100) {
            throw ValidationException::withMessages([
                'split_contractor_pct' => 'Contractor share must be between 0 and 100 (exclusive of extremes).',
            ]);
        }
        if ($pm < 0 || $company < 0 || $pm > 99 || $company > 99) {
            throw ValidationException::withMessages([
                'split' => 'PM and company shares must be between 0 and 99.',
            ]);
        }

        $total = round($contractor + $pm + $company, 2);
        if (abs($total - 100) > 0.01) {
            throw ValidationException::withMessages([
                'split' => "Split percentages must total 100 (currently {$total}).",
            ]);
        }
    }

    public function assertRatesSane(float $gstRate, float $contractorPct): void
    {
        if ($gstRate < 0 || $gstRate > 30) {
            throw ValidationException::withMessages([
                'gst_rate' => 'GST rate must be between 0 and 30%.',
            ]);
        }
        if ($contractorPct < 50 || $contractorPct > 95) {
            throw ValidationException::withMessages([
                'split_contractor_pct' => 'Contractor share outside safe range (50–95%). Extreme values are blocked.',
            ]);
        }
    }
}
