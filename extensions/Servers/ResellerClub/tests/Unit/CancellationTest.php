<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\CancellationService;
use Paymenter\Extensions\Servers\ResellerClub\Core\InMemoryOperationStore;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\LegacyHttpClient;
use Paymenter\Extensions\Servers\ResellerClub\Registrar\RegistrarException;
use Paymenter\Extensions\Servers\ResellerClub\Tests\FakeRegistrarClient;

class CancellationTest extends TestCase
{
    public function test_accepted_deletion_is_submitted_and_polled_like_other_actions(): void
    {
        $client = new FakeRegistrarClient();
        $store = new InMemoryOperationStore();
        $this->assertSame('submitted', (new CancellationService($client, $store))->cancel(5, 777));
        $op = $store->find('cancel:5');
        $this->assertSame('submitted', $op['status']);
        $this->assertSame(903, $op['rc_action_id']);
    }

    public function test_a_refusal_outside_the_grace_period_is_recorded_as_completed_with_a_note(): void
    {
        $client = new FakeRegistrarClient();
        $client->deleteError = new RegistrarException('Domain cannot be deleted', RegistrarException::API);
        $store = new InMemoryOperationStore();
        $this->assertSame('completed', (new CancellationService($client, $store))->cancel(5, 777));
        $this->assertStringContainsString('will expire', $store->find('cancel:5')['last_error']);
    }

    public function test_an_outage_is_a_failed_operation_an_admin_can_see(): void
    {
        $client = new FakeRegistrarClient();
        $client->deleteError = new RegistrarException('down', RegistrarException::UNREACHABLE);
        $store = new InMemoryOperationStore();
        $this->assertSame('failed', (new CancellationService($client, $store))->cancel(5, 777));
    }

    public function test_cancelling_twice_asks_the_registrar_once(): void
    {
        $client = new FakeRegistrarClient();
        $store = new InMemoryOperationStore();
        $svc = new CancellationService($client, $store);
        $svc->cancel(5, 777);
        $svc->cancel(5, 777);
        $this->assertSame(1, $client->count('deleteOrder'));
    }

    private function http(array $details, ?array &$calls = null): LegacyHttpClient
    {
        $calls = [];

        return new LegacyHttpClient('123', 'SECRETKEY', false, function ($m, $url, $p) use ($details, &$calls) {
            $calls[] = ['method' => $m, 'url' => $url, 'params' => $p];

            return ['status' => 200, 'body' => json_encode(str_contains($url, 'details.json') ? $details : ['status' => 'Success', 'eaqid' => 9])];
        });
    }

    public function test_auth_code_is_read_from_the_order_details(): void
    {
        $this->assertSame('abc-123', $this->http(['domsecret' => 'abc-123'], $calls)->authCode(42));
        $this->assertSame(42, $calls[0]['params']['order-id']);
    }

    public function test_a_missing_auth_code_is_an_error_not_an_empty_string(): void
    {
        $this->expectException(RegistrarException::class);
        $this->http(['domsecret' => ''])->authCode(42);
    }

    public function test_lock_state_and_changes(): void
    {
        $this->assertTrue($this->http(['orderstatus' => ['transferlock', 'x']])->transferLock(42));
        $this->assertFalse($this->http(['orderstatus' => ['other']])->transferLock(42));
        $this->assertNull($this->http(['nothing' => 1])->transferLock(42));

        $c = $this->http([], $calls);
        $c->setTransferLock(42, true);
        $c->setTransferLock(42, false);
        $this->assertStringEndsWith('domains/enable-theft-protection.json', $calls[0]['url']);
        $this->assertStringEndsWith('domains/disable-theft-protection.json', $calls[1]['url']);
        $this->assertSame('POST', $calls[0]['method']);
    }

    public function test_delete_is_a_post_with_the_order_id(): void
    {
        $c = $this->http([], $calls);
        $r = $c->deleteOrder(42);
        $this->assertStringEndsWith('domains/delete.json', $calls[0]['url']);
        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame(42, $calls[0]['params']['order-id']);
        $this->assertSame(9, $r->actionId);
    }
}
