<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

/**
 * Keeps a hosting cart item and the domain item bundled with it in step. Pure, so it is unit tested.
 *
 * A bundled domain item carries `bundled_by` = the hosting item's id in its checkout config. When the hosting item is
 * edited, the bundled item must follow: a changed name or register/transfer choice replaces it, choosing "own domain"
 * removes it, and an unchanged choice leaves it alone.
 */
class CartBundle
{
    public const MARKER = 'bundled_by';

    /**
     * @param string|null $option the hosting item's choice: register, transfer, own (or null)
     * @param string|null $domain the full domain the hosting item names, lower case, or null when not sellable
     * @param array<int,array{domain:string,action:string,auth:string}> $existing bundled items, keyed by cart item id
     * @return array{delete:int[], keep:?int, create:bool, auth:?string} auth = new auth code to store on the kept transfer item
     */
    public static function decide(?string $option, ?string $domain, array $existing, string $authCode = ''): array
    {
        $wanted = in_array($option, ['register', 'transfer'], true) && $domain !== null && $domain !== '';
        if (!$wanted) {
            return ['delete' => array_keys($existing), 'keep' => null, 'create' => false, 'auth' => null];
        }

        $keep = null;
        foreach ($existing as $id => $row) {
            if ($keep === null && $row['domain'] === $domain && $row['action'] === $option) {
                $keep = $id;
            }
        }
        $delete = array_values(array_filter(array_keys($existing), fn ($id) => $id !== $keep));
        $auth = ($keep !== null && $option === 'transfer' && $authCode !== '' && $authCode !== $existing[$keep]['auth']) ? $authCode : null;

        return ['delete' => $delete, 'keep' => $keep, 'create' => $keep === null, 'auth' => $auth];
    }
}
