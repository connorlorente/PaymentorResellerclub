<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;

/**
 * Checkout rule: the name must belong to this product's TLD and still be available.
 * This catches a name taken since the search; a name taken between checkout and payment is the failed-operation path.
 */
class DomainAvailable implements ValidationRule
{
    public function __construct(private readonly AvailabilityChecker $checker, private readonly string $tld) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = AvailabilityChecker::split((string) $value);
        if (!$parts) {
            $fail('Enter a valid domain name, for example example.' . $this->tld . '.');

            return;
        }
        [$label, $tld] = $parts;
        if ($tld !== strtolower($this->tld)) {
            $fail('This product is for .' . $this->tld . ' domains only.');

            return;
        }

        match ($this->checker->check($label, [$tld])[$tld] ?? 'unknown') {
            'available' => null,
            'taken' => $fail('That domain is no longer available.'),
            'premium' => $fail('Premium domains cannot be ordered here.'),
            default => $fail('We could not check that domain right now. Please try again in a moment.'),
        };
    }
}
