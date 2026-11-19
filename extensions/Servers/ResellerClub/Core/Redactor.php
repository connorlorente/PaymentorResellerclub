<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/** Keeps API keys and auth codes out of stored payloads and errors. */
final class Redactor
{
    private const SECRET_KEYS = ['api-key', 'api_key', 'apikey', 'passwd', 'password', 'auth-code', 'auth_code', 'otp'];

    public static function payload(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if (in_array(strtolower((string) $k), self::SECRET_KEYS, true)) {
                $payload[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $payload[$k] = self::payload($v);
            }
        }

        return $payload;
    }

    public static function text(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }

        return preg_replace('/(api-key|auth-code|passwd)=[^&\s]+/i', '$1=[redacted]', $s);
    }
}
