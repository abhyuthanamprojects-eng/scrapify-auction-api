<?php

namespace Tests;

use App\Models\Otp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function markRegistrationIdentityVerified(string $email, string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        Otp::insert([
            [
                'identifier' => strtolower(trim($email)),
                'channel' => 'email',
                'purpose' => 'register',
                'code' => 'verified',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'identifier' => $digits,
                'channel' => 'sms',
                'purpose' => 'register',
                'code' => 'managed',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5),
                'consumed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
