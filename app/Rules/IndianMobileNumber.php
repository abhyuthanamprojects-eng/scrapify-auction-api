<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class IndianMobileNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = preg_replace('/\D+/', '', trim((string) $value)) ?? '';
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
            $fail('Enter a valid Indian mobile number.');
        }
    }
}
