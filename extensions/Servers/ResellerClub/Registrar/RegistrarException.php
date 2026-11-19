<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Registrar;

class RegistrarException extends \RuntimeException
{
    public const AUTH = 'auth';
    public const IP_NOT_WHITELISTED = 'ip';
    public const UNREACHABLE = 'unreachable';
    public const TIMEOUT = 'timeout';
    public const FUNDS = 'funds';
    public const TAKEN = 'taken';
    public const API = 'api';

    public function __construct(string $message, public readonly string $kind = self::API)
    {
        parent::__construct($message);
    }

    /** A timed-out write may have succeeded: look the order up before retrying. */
    public function isOutcomeUnknown(): bool
    {
        return $this->kind === self::TIMEOUT;
    }
}
