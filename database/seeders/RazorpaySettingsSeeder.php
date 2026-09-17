<?php

namespace Database\Seeders;

use App\Models\GeneralSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class RazorpaySettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'razorpay_enabled' => '1',
            'razorpay_environment' => 'test',
            'razorpay_timeout' => '30',
        ];

        $secrets = [
            'razorpay_key_id' => 'rzp_test_Td9KjuoOQKL3Ol',
            'razorpay_key_secret' => '9xQtTKiXwdj1jrwCbHFZcK6D',
        ];

        foreach ($settings as $key => $value) {
            GeneralSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        foreach ($secrets as $key => $value) {
            GeneralSetting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString($value)]);
        }
    }
}
