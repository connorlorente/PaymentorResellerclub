<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;

/** Availability with a short cache. Results: available, taken, premium, unknown. */
class AvailabilityChecker
{
    public const TTL = 60;

    /** ResellerClub returns `unknown` past 5 names per Donuts TLD group, so ask for few TLDs at a time. */
    private const CHUNK = 5;

    /**
     * @param callable(string $key): mixed $get returns null on a miss
     * @param callable(string $key, string $value, int $ttl): void $put
     */
    public function __construct(private readonly RegistrarClient $client, private $get, private $put) {}

    /** Splits "label.tld" at the first dot; null when it does not look like a domain. @return array{0:string,1:string}|null */
    public static function split(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)\.([a-z0-9.-]+)$/', $domain, $m)) {
            return null;
        }

        return [$m[1], $m[3]];
    }

    /** Maps registrar statuses to the four shown to customers. */
    public static function simplify(string $registrarStatus): string
    {
        return match ($registrarStatus) {
            'available' => 'available',
            'regthroughus', 'regthroughothers' => 'taken',
            'premium' => 'premium',
            default => 'unknown',
        };
    }

    /**
     * @param string[] $tlds
     * @return array<string,string> tld => simplified status
     */
    public function check(string $label, array $tlds): array
    {
        $out = [];
        $missing = [];
        foreach ($tlds as $tld) {
            $cached = ($this->get)("resellerclub.avail.$label.$tld");
            if ($cached !== null) {
                $out[$tld] = $cached;
            } else {
                $missing[] = $tld;
            }
        }
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            try {
                $res = $this->client->checkAvailability([$label], $chunk);
            } catch (\Throwable) {
                foreach ($chunk as $tld) {
                    $out[$tld] = 'unknown'; // not cached, so the next search retries
                }

                continue;
            }
            foreach ($chunk as $tld) {
                $status = self::simplify($res["$label.$tld"] ?? 'unknown');
                $out[$tld] = $status;
                if ($status !== 'unknown') {
                    ($this->put)("resellerclub.avail.$label.$tld", $status, self::TTL);
                }
            }
        }

        return $out;
    }
}
