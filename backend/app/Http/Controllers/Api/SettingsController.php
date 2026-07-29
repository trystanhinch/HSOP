<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Setting;
use App\Services\BrandResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $company = Company::withTestData()->orderBy('id')->first();
        $settings = Setting::all()->pluck('value', 'key');

        return response()->json([
            'company' => $company,
            'settings' => $settings,
            'notifications' => [
                'sms_globally_enabled' => Setting::isGloballyEnabled('sms'),
                'email_globally_enabled' => Setting::isGloballyEnabled('email'),
                'sms_enabled' => Setting::isGloballyEnabled('sms'),
                'email_enabled' => Setting::isGloballyEnabled('email'),
                'email_new_lead' => true,
                'email_quote_sent' => true,
                'email_job_update' => true,
            ],
            // A-03: customer payment destinations moved to /payment-destinations.
            // Legacy settings keys retained for audit/history only — not used on customer surfaces.
            'payment' => [
                'managed_via' => 'payment_destinations',
                'legacy_instructions' => $settings['payment_instructions'] ?? null,
                'legacy_company_email' => $settings['company_email'] ?? null,
                'note' => 'Edit customer payment destinations under Settings → Payment (brand-scoped). Contractor payout uses Stripe Connect separately.',
            ],
            // A-06/A-22: Branding is read-only here. The authoritative source is Brand Content.
            // Legacy company_name setting is preserved for audit history only.
            'branding' => [
                'primary_color' => '#3B82F6',
                'company_name' => Brand::where('status', 'active')->value('company_name')
                    ?? $settings['company_name']
                    ?? $company?->name
                    ?? BrandResolver::PLATFORM_NAME,
                '_note' => 'Edit company name under Brand Content, not here. This tab is read-only for legacy audit purposes.',
                '_authoritative_source' => 'Brand Content (Settings → Brand Content)',
                '_legacy_setting' => $settings['company_name'] ?? null,
            ],
            'gst_rate' => $settings['gst_rate'] ?? '5',
            'markup_divisor' => $settings['markup_divisor'] ?? '0.80',
            'split_contractor_pct' => $settings['split_contractor_pct'] ?? '80',
            'split_pm_pct' => $settings['split_pm_pct'] ?? '10',
            'split_company_pct' => $settings['split_company_pct'] ?? '10',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // A-06/A-22: company_name is now managed exclusively via Brand Content.
        // Check before validation so we return a clear 422 rather than silently ignoring.
        if ($request->exists('company_name')) {
            return response()->json([
                'message' => 'Company name is managed via Brand Content (Settings → Brand Content). The legacy Branding tab is read-only.',
            ], 422);
        }

        $data = $request->validate([
            'company_phone' => 'nullable|string|max:20',
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'markup_divisor' => 'nullable|numeric|min:0.01|max:1',
            'split_contractor_pct' => 'nullable|numeric|min:1|max:99',
            'split_pm_pct' => 'nullable|numeric|min:0|max:99',
            'split_company_pct' => 'nullable|numeric|min:0|max:99',
            'sms_globally_enabled' => 'nullable|boolean',
            'email_globally_enabled' => 'nullable|boolean',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'gst_number' => 'nullable|string|max:50',
        ]);

        if ($request->exists('payment_instructions') || $request->exists('company_email')) {
            return response()->json([
                'message' => 'Customer payment destinations are managed via /api/payment-destinations (Settings → Payment). Legacy settings keys are read-only for audit history.',
            ], 422);
        }

        foreach (['company_phone', 'gst_rate', 'markup_divisor', 'split_contractor_pct', 'split_pm_pct', 'split_company_pct', 'sms_globally_enabled', 'email_globally_enabled', 'sms_enabled', 'email_enabled'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $val = is_bool($data[$key]) ? ($data[$key] ? 'true' : 'false') : (string) $data[$key];
                Setting::set($key, $val);
                if ($key === 'sms_enabled') {
                    Setting::set('sms_globally_enabled', $val);
                }
                if ($key === 'email_enabled') {
                    Setting::set('email_globally_enabled', $val);
                }
            }
        }

        $company = Company::withTestData()->orderBy('id')->first();
        if ($company) {
            $companyData = array_filter($request->only(['name', 'email', 'phone', 'address', 'gst_number']), fn ($v) => $v !== null);
            if ($companyData) {
                $company->update($companyData);
            }
        }

        return response()->json(['message' => 'Settings updated']);
    }
}
