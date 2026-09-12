<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class IndianPincode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[1-9]\d{5}$/', trim((string) $value))) {
            $fail('Enter a valid 6-digit Indian PIN code.');
        }
    }
}
