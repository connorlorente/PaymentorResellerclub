<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

final class RegisterRequest
{
    public function __construct(
        public readonly string $domain,
        public readonly int $years,
        public readonly array $nameservers,
        public readonly int $customerId,
        public readonly int $contactId,
        public readonly bool $privacy = false,
        public readonly bool $autoRenew = false,
        /** TLD-specific attr-name/attr-value pairs. */
        public readonly array $attributes = [],
    ) {}
}
