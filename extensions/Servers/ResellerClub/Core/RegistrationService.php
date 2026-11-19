<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Core;

use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegisterRequest;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;

class RegistrationService
{
    public function __construct(
        private readonly RegistrarClient $client,
        private readonly OperationStore $store,
        private readonly bool $allowRegistrations,
    ) {}

    public static function key(int $serviceId): string
    {
        return "register:{$serviceId}";
    }

    /**
     * @param array $registrant contact fields; $user customer fields
     * @return array{status:string, order_id:?int, error:?string}
     */
    public function register(int $serviceId, string $domain, int $years, array $nameservers, array $user, array $registrant, array $options = [], ?int $existingCustomerId = null): array
    {
        $key = self::key($serviceId);
        [$op] = $this->store->firstOrCreate($serviceId, 'register', $key, ['domain' => $domain, 'years' => $years]);

        if (in_array($op['status'], ['submitted', 'completed'], true)) {
            return ['status' => $op['status'], 'order_id' => $op['rc_order_id'], 'error' => null];
        }

        if (!$this->allowRegistrations) {
            return $this->fail($key, 'Registrations disabled: the "Allow new registrations" setting is off.');
        }

        $this->store->update($key, ['attempts' => $op['attempts'] + 1]);

        try {
            // A timed-out earlier attempt may have registered the domain: look first.
            if ($op['attempts'] > 0 && ($orderId = $this->client->orderIdForDomain($domain))) {
                $this->store->update($key, ['status' => 'completed', 'rc_order_id' => $orderId]);

                return ['status' => 'completed', 'order_id' => $orderId, 'error' => null];
            }

            $customerId = $existingCustomerId ?: $this->client->ensureCustomer($user);
            $contactId = $this->client->createContact($registrant, $customerId);
            $res = $this->client->register(new RegisterRequest(
                $domain, $years, $nameservers, $customerId, $contactId,
                (bool) ($options['privacy'] ?? false), (bool) ($options['auto_renew'] ?? false), $options['attributes'] ?? [],
            ));
            $this->store->update($key, ['status' => 'submitted', 'rc_order_id' => $res->orderId, 'rc_action_id' => $res->actionId]);

            return ['status' => 'submitted', 'order_id' => $res->orderId, 'error' => null, 'customer_id' => $customerId, 'contact_id' => $contactId];
        } catch (RegistrarException $e) {
            if ($e->isOutcomeUnknown()) {
                $this->store->update($key, ['last_error' => $e->getMessage()]);
                throw $e;
            }

            return $this->fail($key, $e->getMessage());
        }
    }

    private function fail(string $key, string $error): array
    {
        $this->store->update($key, ['status' => 'failed', 'last_error' => $error]);

        return ['status' => 'failed', 'order_id' => null, 'error' => $error];
    }
}
