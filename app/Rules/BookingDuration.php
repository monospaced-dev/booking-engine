<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BookingDuration implements ValidationRule
{
    public function __construct(private mixed $startsAt) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // The date rules report malformed inputs; only compare valid dates here.
        try {
            $start = Carbon::parse($this->startsAt);
            $end = Carbon::parse($value);
        } catch (\Throwable) {
            return;
        }
        if ($start->diffInSeconds($end, false) > 366 * 86400) {
            $fail('A booking may not be longer than 366 days.');
        }
    }
}
