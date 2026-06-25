<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $company = Company::first();
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
            'payment' => [
                'method' => 'e_transfer',
                'instructions' => $settings['payment_instructions'] ?? 'Send e-transfer to payments@hsop.ca',
            ],
            'branding' => [
                'primary_color' => '#3B82F6',
                'company_name' => $settings['company_name'] ?? $company?->name ?? 'HSOP',
            ],
            'gst_rate' => $settings['gst_rate'] ?? '5',
            'markup_divisor' => $settings['markup_divisor'] ?? '0.80',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => 'nullable|string|max:255',
            'company_email' => 'nullable|email',
            'company_phone' => 'nullable|string|max:20',
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'markup_divisor' => 'nullable|numeric|min:0.01|max:1',
            'sms_globally_enabled' => 'nullable|boolean',
            'email_globally_enabled' => 'nullable|boolean',
            'payment_instructions' => 'nullable|string',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'gst_number' => 'nullable|string|max:50',
        ]);

        foreach (['company_name', 'company_email', 'company_phone', 'gst_rate', 'markup_divisor', 'sms_globally_enabled', 'email_globally_enabled', 'sms_enabled', 'email_enabled', 'payment_instructions'] as $key) {
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

        $company = Company::first();
        if ($company) {
            $companyData = array_filter($request->only(['name', 'email', 'phone', 'address', 'gst_number']), fn ($v) => $v !== null);
            if ($companyData) {
                $company->update($companyData);
            }
        }

        return response()->json(['message' => 'Settings updated']);
    }
}
