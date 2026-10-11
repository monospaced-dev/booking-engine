<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class FlatMetadata implements ValidationRule
{
    public function __construct(private bool $objectInput) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || count($value) > 20 || ($value !== [] && ! $this->objectInput)) {
            $fail('Metadata must be a flat object with at most 20 keys.');
            return;
        }
        foreach ($value as $key => $item) {
            if (! preg_match('/^[A-Za-z0-9_]{1,40}$/D', (string) $key)
                || (! is_int($item) && ! (is_string($item) && mb_strlen($item) <= 500))
                || ($key === 'appointment_id' && ! is_int($item))) {
                $fail('Metadata keys and values must satisfy the flat reference-data limits.');
                return;
            }
        }
    }
}
