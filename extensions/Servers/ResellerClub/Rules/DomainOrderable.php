<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Paymenter\Extensions\Servers\ResellerClub\Core\AvailabilityChecker;

/**
 * Checkout rule for a domain field next to a "domain option" choice:
 *  - register: the name must be free and its TLD one we sell,
 *  - transfer: the name must already be registered (somewhere) and its TLD one we sell,
 *  - own:      only a well-formed name; nothing is ordered.
 */
class DomainOrderable implements ValidationRule, DataAwareRule
{
    public const REGISTER = 'register';
    public const TRANSFER = 'transfer';
    public const OWN = 'own';

    private array $data = [];

    /**
     * @param string[] $tlds TLDs that have a domain product for sale (lowercase, no dot)
     * @param string $optionField checkout field holding register|transfer|own
     */
    public function __construct(
        private readonly ?AvailabilityChecker $checker,
        private readonly array $tlds,
        private readonly string $optionField,
        private readonly string $default = self::REGISTER,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public static function option(array $data, string $optionField, string $default = self::REGISTER): string
    {
        $v = $data['checkoutConfig'][$optionField] ?? $data[$optionField] ?? $default;

        return in_array($v, [self::REGISTER, self::TRANSFER, self::OWN], true) ? $v : $default;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = AvailabilityChecker::split((string) $value);
        if (!$parts || strlen((string) $value) > 253) {
            $fail('Enter a valid domain name, for example example.co.uk (without www or http://).');

            return;
        }
        $option = self::option($this->data, $this->optionField, $this->default);
        if ($option === self::OWN) {
            return;
        }

        [$label, $tld] = $parts;
        if (!in_array($tld, $this->tlds, true)) {
            $fail($option === self::TRANSFER
                ? "We can't transfer .$tld domains. Choose \"I'll use my own domain\" instead."
                : "We don't sell .$tld domains. Choose another extension, or \"I'll use my own domain\".");

            return;
        }
        if (!$this->checker) {
            $fail('Domain ordering is temporarily unavailable. Choose "I\'ll use my own domain" or try again later.');

            return;
        }

        $status = $this->checker->check($label, [$tld])[$tld] ?? 'unknown';
        if ($option === self::REGISTER) {
            match ($status) {
                'available' => null,
                'taken' => $fail('That domain is already registered. Choose "Transfer" if you own it, or try another name.'),
                'premium' => $fail('Premium domains cannot be ordered here.'),
                default => $fail('We could not check that domain right now. Please try again in a moment.'),
            };

            return;
        }

        // Transfer
        match ($status) {
            'taken' => null,
            'available' => $fail('That domain is not registered yet, so it cannot be transferred. Choose "Register" instead.'),
            'premium' => $fail('Premium domains cannot be transferred here.'),
            default => $fail('We could not check that domain right now. Please try again in a moment.'),
        };
    }
}
