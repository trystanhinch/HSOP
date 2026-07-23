<?php

namespace App\Services\Pricing;

use App\Models\Brand;
use App\Models\PricingRule;

/**
 * Deterministic customer-facing price RANGE estimator.
 * Does NOT touch Quote / 80-10-10 contractor split math (PricingService).
 */
class PricingRangeEstimator
{
    public const DISCLAIMER = 'This is an estimate only. Final pricing depends on an on-site or detailed assessment.';

    /**
     * @param  array{
     *   service_category?: string|null,
     *   size_sqft?: float|int|string|null,
     *   complexity?: string|null,
     *   urgency?: string|null,
     *   project_description?: string|null,
     *   address?: string|null
     * }  $inputs
     * @return array{
     *   available: bool,
     *   low: float|null,
     *   high: float|null,
     *   currency: string,
     *   disclaimer: string,
     *   rule_id: int|null,
     *   rule_type: string|null,
     *   is_placeholder: bool,
     *   confidence: string,
     *   widened: bool,
     *   inputs_used: array<string, mixed>,
     *   calculation: list<string>,
     *   message: string|null
     * }
     */
    public function estimate(Brand $brand, array $inputs): array
    {
        $category = trim((string) ($inputs['service_category'] ?? ''));
        $calculation = [];
        $inputsUsed = [
            'brand_id' => $brand->id,
            'brand_domain' => $brand->domain,
            'service_category' => $category !== '' ? $category : null,
            'size_sqft' => $this->normalizeSize($inputs['size_sqft'] ?? null),
            'complexity' => $this->normalizeComplexity($inputs['complexity'] ?? null, $inputs['project_description'] ?? null),
            'urgency' => in_array(($inputs['urgency'] ?? null), ['normal', 'high'], true) ? $inputs['urgency'] : 'normal',
            'has_description' => ! empty(trim((string) ($inputs['project_description'] ?? ''))),
            'has_address' => ! empty(trim((string) ($inputs['address'] ?? ''))),
        ];

        if ($category === '') {
            return $this->unavailable('Need a service category before estimating.', $inputsUsed, $calculation);
        }

        $rule = PricingRule::query()
            ->where('brand_id', $brand->id)
            ->where('service_category', $category)
            ->where('status', 'active')
            ->first();

        if (! $rule) {
            $calculation[] = "No active pricing rule for brand={$brand->id} category={$category}";

            return $this->unavailable('No pricing rule configured for this service yet.', $inputsUsed, $calculation);
        }

        $calculation[] = "Matched rule #{$rule->id} type={$rule->rule_type} base_rate={$rule->base_rate}";
        $size = $inputsUsed['size_sqft'];
        $complexity = $inputsUsed['complexity'];
        $urgency = $inputsUsed['urgency'];

        [$low, $high, $widened] = match ($rule->rule_type) {
            'flat' => $this->estimateFlat($rule, $size, $complexity, $urgency, $calculation),
            'tiered' => $this->estimateTiered($rule, $size, $complexity, $urgency, $calculation),
            default => $this->estimatePerSqft($rule, $size, $complexity, $urgency, $calculation),
        };

        [$low, $high] = $this->applyFloorCeiling($rule, $low, $high, $calculation);

        $confidence = $this->confidence($size !== null, $inputsUsed['has_description'], $widened);

        $low = round(max(0, $low), 2);
        $high = round(max($low, $high), 2);

        $assumptions = $this->buildAssumptions(
            $category,
            $inputsUsed['size_sqft'],
            $inputsUsed['complexity'],
            $calculation
        );

        return [
            'available' => true,
            'low' => $low,
            'high' => $high,
            'currency' => $rule->currency ?: 'CAD',
            'disclaimer' => self::DISCLAIMER,
            'rule_id' => $rule->id,
            'rule_type' => $rule->rule_type,
            'is_placeholder' => (bool) $rule->is_placeholder,
            'confidence' => $confidence,
            'widened' => $widened,
            'inputs_used' => $inputsUsed,
            'calculation' => $calculation,
            'message' => $this->customerMessage($low, $high, $rule->currency ?: 'CAD', $widened),
            // Learning Centre foundation (deterministic assumptions — not AI learning)
            'materials_assumptions' => $assumptions['materials'],
            'labour_assumptions' => $assumptions['labour'],
            'estimator_engine' => 'pricing_range_v1',
            'service_category' => $category,
        ];
    }

    /**
     * Deterministic material/labour assumptions derived from size/complexity.
     * Capture-only for future Learning Centre — not recommendations.
     *
     * @param  list<string>  $calculation
     * @return array{materials: list<array<string, mixed>>, labour: array<string, mixed>}
     */
    private function buildAssumptions(string $category, ?float $size, string $complexity, array &$calculation): array
    {
        $sqft = $size && $size > 0 ? $size : null;
        $complexityFactor = match ($complexity) {
            'simple' => 0.8,
            'complex' => 1.4,
            default => 1.0,
        };

        $labourHours = null;
        if ($sqft) {
            // PLACEHOLDER productivity heuristics — locked into snapshot for learning later
            $hoursPer100 = str_contains($category, 'insulation') ? 2.5 : 4.0;
            $labourHours = round(($sqft / 100) * $hoursPer100 * $complexityFactor, 1);
            $calculation[] = "Labour assumption: ~{$labourHours}h from {$sqft} sqft × {$hoursPer100}h/100sqft × {$complexityFactor}";
        } else {
            $calculation[] = 'Labour assumption: unknown (size missing) — store null hours with band';
        }

        $materials = [];
        if (str_contains($category, 'insulation')) {
            $bags = $sqft ? (int) max(1, ceil($sqft / 40)) : null;
            $materials[] = [
                'item' => 'insulation_batt_or_blown',
                'unit' => 'bag_or_pack',
                'qty_assumed' => $bags,
                'note' => 'PLACEHOLDER heuristic from sqft',
            ];
        } else {
            $sheets = $sqft ? (int) max(1, ceil($sqft / 32)) : null;
            $materials[] = [
                'item' => 'drywall_sheet_4x8',
                'unit' => 'sheet',
                'qty_assumed' => $sheets,
                'note' => 'PLACEHOLDER heuristic from sqft',
            ];
            $materials[] = [
                'item' => 'joint_compound',
                'unit' => 'pail',
                'qty_assumed' => $sheets ? max(1, (int) ceil($sheets / 8)) : null,
                'note' => 'PLACEHOLDER heuristic',
            ];
            $materials[] = [
                'item' => 'paint_finish',
                'unit' => 'gallon',
                'qty_assumed' => $sqft ? max(1, (int) ceil($sqft / 350)) : null,
                'note' => 'PLACEHOLDER heuristic',
            ];
        }

        $calculation[] = 'Materials assumptions recorded ('.count($materials).' line items)';

        return [
            'materials' => $materials,
            'labour' => [
                'estimated_hours' => $labourHours,
                'estimated_hours_low' => $labourHours !== null ? round($labourHours * 0.85, 1) : null,
                'estimated_hours_high' => $labourHours !== null ? round($labourHours * 1.25, 1) : null,
                'basis' => $sqft
                    ? 'deterministic_sqft_heuristic'
                    : 'size_unknown',
                'complexity' => $complexity,
                'is_placeholder' => true,
            ],
        ];
    }

    /**
     * @param  list<string>  $calculation
     * @return array{0: float, 1: float, 2: bool}
     */
    private function estimatePerSqft(
        PricingRule $rule,
        ?float $size,
        string $complexity,
        string $urgency,
        array &$calculation,
    ): array {
        $base = (float) ($rule->base_rate ?? 0);
        $tiers = is_array($rule->size_tiers) ? $rule->size_tiers : [];
        $mods = is_array($rule->complexity_modifiers) ? $rule->complexity_modifiers : [];

        $lowRate = (float) ($tiers['low_rate'] ?? ($base * 0.85));
        $highRate = (float) ($tiers['high_rate'] ?? ($base * 1.25));
        $defaultLowSqft = (float) ($tiers['default_low_sqft'] ?? 80);
        $defaultHighSqft = (float) ($tiers['default_high_sqft'] ?? 250);
        $widened = false;

        if ($size === null || $size <= 0) {
            $widened = true;
            $sizeLow = $defaultLowSqft;
            $sizeHigh = $defaultHighSqft;
            $calculation[] = "Size missing — widened using default sqft band {$sizeLow}-{$sizeHigh}";
        } else {
            $sizeLow = $size;
            $sizeHigh = $size;
            $calculation[] = "Size known: {$size} sqft";
            // Small uncertainty band even with known size
            if ($size < 50) {
                $widened = true;
                $sizeLow = max(1, $size * 0.85);
                $sizeHigh = $size * 1.35;
                $calculation[] = 'Small job — widened size band ±';
            }
        }

        $complexityMult = (float) ($mods[$complexity] ?? $mods['standard'] ?? 1.0);
        $urgencyMult = $urgency === 'high' ? (float) ($mods['urgency_high'] ?? 1.12) : 1.0;
        $calculation[] = "Rates {$lowRate}-{$highRate}/sqft × complexity={$complexityMult} ({$complexity}) × urgency={$urgencyMult}";

        $low = $sizeLow * $lowRate * $complexityMult * $urgencyMult;
        $high = $sizeHigh * $highRate * $complexityMult * $urgencyMult;

        if (! ($size !== null && $size > 0) || ! ($mods[$complexity] ?? null)) {
            // Missing complexity / size → push high further
            if ($complexity === 'unknown') {
                $widened = true;
                $high *= 1.2;
                $calculation[] = 'Unknown complexity — +20% high bound';
            }
        }

        return [$low, $high, $widened];
    }

    /**
     * @param  list<string>  $calculation
     * @return array{0: float, 1: float, 2: bool}
     */
    private function estimateFlat(
        PricingRule $rule,
        ?float $size,
        string $complexity,
        string $urgency,
        array &$calculation,
    ): array {
        $base = (float) ($rule->base_rate ?? 0);
        $mods = is_array($rule->complexity_modifiers) ? $rule->complexity_modifiers : [];
        $tiers = is_array($rule->size_tiers) ? $rule->size_tiers : [];
        $spread = (float) ($tiers['spread_pct'] ?? 0.25);
        $complexityMult = (float) ($mods[$complexity] ?? $mods['standard'] ?? 1.0);
        $urgencyMult = $urgency === 'high' ? (float) ($mods['urgency_high'] ?? 1.12) : 1.0;
        $widened = $size === null || $complexity === 'unknown';

        $mid = $base * $complexityMult * $urgencyMult;
        $low = $mid * (1 - $spread);
        $high = $mid * (1 + $spread);
        if ($widened) {
            $high *= 1.15;
            $calculation[] = 'Flat rule with incomplete inputs — widened high bound';
        }
        $calculation[] = "Flat base={$base} mid={$mid} spread={$spread}";

        return [$low, $high, $widened];
    }

    /**
     * @param  list<string>  $calculation
     * @return array{0: float, 1: float, 2: bool}
     */
    private function estimateTiered(
        PricingRule $rule,
        ?float $size,
        string $complexity,
        string $urgency,
        array &$calculation,
    ): array {
        $tiers = is_array($rule->size_tiers) ? $rule->size_tiers : [];
        $bands = is_array($tiers['bands'] ?? null) ? $tiers['bands'] : [];
        $widened = false;

        if ($bands === []) {
            return $this->estimatePerSqft($rule, $size, $complexity, $urgency, $calculation);
        }

        if ($size === null || $size <= 0) {
            $widened = true;
            // Use outermost band lows/highs
            $low = (float) ($bands[0]['low'] ?? $rule->min_price ?? 500);
            $high = (float) ($bands[count($bands) - 1]['high'] ?? $rule->max_price ?? 5000);
            $calculation[] = "Size missing — using outermost tier band {$low}-{$high}";
        } else {
            $matched = $bands[count($bands) - 1];
            foreach ($bands as $band) {
                $maxSqft = $band['max_sqft'] ?? null;
                if ($maxSqft === null || $size <= (float) $maxSqft) {
                    $matched = $band;
                    break;
                }
            }
            $low = (float) ($matched['low'] ?? 0);
            $high = (float) ($matched['high'] ?? $low);
            $calculation[] = 'Tier matched: '.json_encode($matched);
        }

        $mods = is_array($rule->complexity_modifiers) ? $rule->complexity_modifiers : [];
        $complexityMult = (float) ($mods[$complexity] ?? $mods['standard'] ?? 1.0);
        $urgencyMult = $urgency === 'high' ? (float) ($mods['urgency_high'] ?? 1.12) : 1.0;
        $low *= $complexityMult * $urgencyMult;
        $high *= $complexityMult * $urgencyMult;
        if ($complexity === 'unknown') {
            $widened = true;
            $high *= 1.15;
        }

        return [$low, $high, $widened];
    }

    /**
     * @param  list<string>  $calculation
     * @return array{0: float, 1: float}
     */
    private function applyFloorCeiling(PricingRule $rule, float $low, float $high, array &$calculation): array
    {
        if ($rule->min_price !== null && $low < (float) $rule->min_price) {
            $calculation[] = "Applied min_price floor {$rule->min_price}";
            $low = (float) $rule->min_price;
        }
        if ($rule->min_price !== null && $high < (float) $rule->min_price) {
            $high = (float) $rule->min_price;
        }
        if ($rule->max_price !== null && $high > (float) $rule->max_price) {
            $calculation[] = "Applied max_price ceiling {$rule->max_price}";
            $high = (float) $rule->max_price;
        }
        if ($high < $low) {
            $high = $low;
        }

        return [$low, $high];
    }

    private function normalizeSize(mixed $raw): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            $n = (float) $raw;

            return $n > 0 ? $n : null;
        }
        if (is_string($raw) && preg_match('/(\d+(?:\.\d+)?)\s*(sq\.?\s*ft|sqft|sf)?/i', $raw, $m)) {
            $n = (float) $m[1];

            return $n > 0 ? $n : null;
        }

        return null;
    }

    private function normalizeComplexity(?string $explicit, mixed $description): string
    {
        if (in_array($explicit, ['simple', 'standard', 'complex', 'unknown'], true)) {
            return $explicit;
        }

        $text = strtolower((string) $description);
        if ($text === '') {
            return 'unknown';
        }
        if (preg_match('/\b(water damage|mold|asbestos|structural|multi-?room|full (?:house|home)|commercial)\b/', $text)) {
            return 'complex';
        }
        if (preg_match('/\b(patch|small|touch-?up|single (?:hole|spot)|minor)\b/', $text)) {
            return 'simple';
        }

        return 'standard';
    }

    private function confidence(bool $hasSize, bool $hasDescription, bool $widened): string
    {
        if ($hasSize && $hasDescription && ! $widened) {
            return 'high';
        }
        if ($hasSize || $hasDescription) {
            return 'medium';
        }

        return 'low';
    }

    private function customerMessage(float $low, float $high, string $currency, bool $widened): string
    {
        $fmt = fn ($n) => '$'.number_format($n, 0);
        $range = $fmt($low).' – '.$fmt($high).' '.$currency;
        $extra = $widened
            ? ' Range is wider because some project details are still approximate.'
            : '';

        return "Based on what you've shared, a ballpark estimate is {$range}. ".self::DISCLAIMER.$extra;
    }

    /**
     * @param  array<string, mixed>  $inputsUsed
     * @param  list<string>  $calculation
     * @return array<string, mixed>
     */
    private function unavailable(string $message, array $inputsUsed, array $calculation): array
    {
        return [
            'available' => false,
            'low' => null,
            'high' => null,
            'currency' => 'CAD',
            'disclaimer' => self::DISCLAIMER,
            'rule_id' => null,
            'rule_type' => null,
            'is_placeholder' => false,
            'confidence' => 'none',
            'widened' => true,
            'inputs_used' => $inputsUsed,
            'calculation' => $calculation,
            'message' => $message,
            'estimator_engine' => 'pricing_range_v1',
            'service_category' => $inputsUsed['service_category'] ?? null,
        ];
    }
}
