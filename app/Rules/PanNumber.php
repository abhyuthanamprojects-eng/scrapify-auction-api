<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PanNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', strtoupper(trim((string) $value)))) {
            $fail('Enter a valid 10-character PAN number.');
        }
    }
}
