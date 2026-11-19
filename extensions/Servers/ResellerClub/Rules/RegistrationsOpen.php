<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RegistrationsOpen implements ValidationRule
{
    public function __construct(private readonly bool $open) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->open) {
            $fail('New domain registrations are currently disabled.');
        }
    }
}
