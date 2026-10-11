<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ExplicitDatetimeOffset implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            $fail('An explicit offset is required for :attribute (Z or +/-hh:mm).');
        }
    }
}
