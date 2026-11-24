<?php

namespace Paymenter\Extensions\Servers\PleskDomains;

use App\Attributes\ExtensionMeta;
use App\Helpers\ExtensionHelper;
use App\Models\Server as ServerModel;
use App\Rules\Domain;
use Paymenter\Extensions\Servers\Plesk\Plesk;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;
use Paymenter\Extensions\Servers\ResellerClub\Rules\DomainOrderable;

/**
 * Plesk hosting with a WHMCS-style domain step at checkout.
 *
 * Everything Plesk does (create, suspend, unsuspend, terminate, the "domain" property it uses) is inherited
 * unchanged from the built-in Plesk module. Only the checkout fields differ:
 *   domain_option    register | transfer | own
 *   domain           the hosting's domain (the same field Plesk reads)
 *   domain_auth_code only for a transfer
 * The ResellerClub extension sees a register/transfer choice when the item reaches the cart and adds the
 * matching domain product next to the hosting.
 */
#[ExtensionMeta(
    name: 'Plesk + Domains',
    description: 'The built-in Plesk module with a "register / transfer / use my own domain" step at checkout (ResellerClub).',
    version: '1.0.2',
    author: 'Virtual Developments',
)]
class PleskDomains extends Plesk
{
    // Same values as ResellerClub's DomainOrderable, kept local so this module works without ResellerClub.
    private const REGISTER = 'register';
    private const TRANSFER = 'transfer';
    private const OWN = 'own';

    public function getCheckoutConfig($product = null, $values = [], $settings = [])
    {
        $values = (array) $values;
        $rc = $this->resellerClub();
        $tlds = $rc ? array_keys(ResellerClub::sellableProducts()) : [];

        $options = [];
        if ($rc && $tlds && $rc->registrationsAllowed()) {
            $options[self::REGISTER] = 'Register a new domain';
        }
        if ($rc && $tlds && $rc->registrationsAllowed() && $rc->transfersAllowed()) {
            $options[self::TRANSFER] = 'Transfer my domain to you';
        }
        $options[self::OWN] = "I'll use a domain I already own";

        $default = array_key_first($options);
        $chosen = $values['domain_option'] ?? $default;
        if (!isset($options[$chosen])) {
            $chosen = $default;
        }

        $fields = [];
        if (count($options) > 1) {
            $fields[] = [
                'name' => 'domain_option',
                'type' => 'radio',
                'label' => 'Domain',
                'options' => $options,
                'default' => $default,
                'required' => true,
                'validation' => ['in:' . implode(',', array_keys($options))],
            ];
        }

        $rule = $rc
            ? new DomainOrderable($rc->availability(), $tlds, 'domain_option', $default)
            : new Domain();
        $fields[] = [
            'name' => 'domain',
            'type' => 'text',
            'label' => match ($chosen) {
                self::REGISTER => 'Domain to register' . ($tlds ? ' (' . implode(', ', array_map(fn ($t) => ".$t", array_slice($tlds, 0, 8))) . (count($tlds) > 8 ? ', …' : '') . ')' : ''),
                self::TRANSFER => 'Domain to transfer',
                default => 'Your domain',
            },
            'required' => true,
            'validation' => ['bail', 'required', $rule],
            'placeholder' => 'example.co.uk',
        ];

        if ($chosen === self::TRANSFER) {
            $fields[] = [
                'name' => 'domain_auth_code',
                'type' => 'text',
                'label' => 'Transfer (EPP/auth) code from your current provider',
                'required' => true,
                'validation' => ['required', 'string', 'max:255'],
            ];
        }

        return $fields;
    }

    /** Same fields as Plesk, but optional: left blank, the connection of the existing Plesk server is reused. */
    public function getConfig($values = []): array
    {
        return array_map(function (array $field) {
            $field['required'] = false;
            $field['description'] = rtrim((string) ($field['description'] ?? ''), '. ') . '. Leave blank to reuse the existing Plesk server\'s connection.';

            return $field;
        }, parent::getConfig($values));
    }

    public function config($key)
    {
        try {
            $own = parent::config($key);
        } catch (\Throwable) {
            $own = null;
        }
        if ($own !== null && $own !== '') {
            return $own;
        }

        return $this->pleskConfig((string) $key);
    }

    /** Connection settings of the first plain Plesk server (host, username, password), read once per request. */
    private function pleskConfig(string $key): mixed
    {
        static $settings = null;
        if ($settings === null) {
            try {
                $server = ServerModel::where('extension', 'Plesk')->first();
                $settings = $server ? ExtensionHelper::settingsToArray($server->settings) : [];
            } catch (\Throwable) {
                $settings = [];
            }
        }

        return $settings[$key] ?? null;
    }

    /** The configured ResellerClub module, or null when it is not installed/configured (checkout then behaves like plain Plesk). */
    private function resellerClub(): ?ResellerClub
    {
        try {
            if (!class_exists(ResellerClub::class)) {
                return null;
            }
            $server = ServerModel::where('extension', 'ResellerClub')->first();

            return $server ? ResellerClub::forServer($server) : null;
        } catch (\Throwable $e) {
            try { report($e); } catch (\Throwable) {}

            return null;
        }
    }
}
