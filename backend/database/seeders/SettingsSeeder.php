<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'company_name' => 'HSOP Drywall & Paint',
            'company_email' => 'info@hsop.ca',
            'company_phone' => '604-000-0000',
            'gst_rate' => '5',
            'markup_divisor' => '0.80',
            'sms_globally_enabled' => 'false',
            'email_globally_enabled' => 'false',
            'payment_instructions' => 'Send e-transfer to payments@hsop.ca',
        ];

        foreach ($defaults as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
