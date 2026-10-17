<?php

namespace Tests\Feature\Modules;

use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\PaymentIntent;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Providers\YooMoneyProvider;
use Tests\TestCase;

final class YooMoneyDriverTest extends TestCase
{
    private const CREDENTIALS = ['receiver' => '410012345678901', 'notification_secret' => 'secret123'];

    private const UUID = '12345678-1234-1234-1234-123456789abc';

    private function body(array $params): string
    {
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name.'='.rawurlencode($value);
        }
        $encoded = implode('&', $pairs);

        return $encoded.'&sign='.hash_hmac('sha256', $encoded, 'secret123');
    }

    /** @return array<string,string> */
    private function fields(): array
    {
        return [
            'notification_type' => 'p2p-incoming',
            'operation_id' => '441361714955017004',
            'amount' => '98.00',
            'withdraw_amount' => '100.00',
            'currency' => '643',
            'datetime' => '2013-12-26T08:28:34Z',
            'sender' => '41000000000',
            'codepro' => 'false',
            'unaccepted' => 'false',
            'label' => YooMoneyProvider::label(self::UUID),
            'test_notification' => 'false',
        ];
    }

    public function test_post_checkout_dto_is_server_priced_without_known_operation_id(): void
    {
        $driver = new YooMoneyProvider;
        $this->assertSame(['RUB'], $driver->supportedCurrencies());
        $checkout = $driver->beginPayment(new PaymentIntent(self::UUID, 100, 'RUB', 100000, 2,
            'https://example.org/notify', 'https://example.org/orders'), self::CREDENTIALS, 'live');
        $this->assertSame('POST', $checkout->method);
        $this->assertSame(YooMoneyProvider::CHECKOUT_URL, $checkout->redirectUrl);
        $this->assertSame('1000.00', $checkout->fields['sum']);
        $this->assertSame('410012345678901', $checkout->fields['receiver']);
        $this->assertSame(YooMoneyProvider::label(self::UUID), $checkout->fields['label']);
        $this->assertNull($checkout->providerPaymentId);
        $this->expectException(InvalidArgumentException::class);
        $driver->beginPayment(new PaymentIntent(self::UUID, 100, 'USD', 100000, 2, '', ''), self::CREDENTIALS, 'live');
    }

    public function test_signed_notification_is_not_settlement_and_preserves_fee_difference(): void
    {
        $driver = new YooMoneyProvider;
        $params = $this->fields();
        $verified = $driver->verifyCallback(new ProviderCallback($this->body($params), [], ''), self::CREDENTIALS, 'live');
        $this->assertSame(self::UUID, $verified->orderUuid);
        $this->assertSame('received_unsettled', $verified->status);
        $this->assertSame(10000, $verified->amountMinor); // Debited from payer.
        $this->assertSame(9800, $verified->receivedAmountMinor); // Received net of fee.
        $this->assertSame('live', $verified->environment);
        $this->assertFalse($verified->testNotification);
        $params['test_notification'] = 'true';
        $tested = $driver->verifyCallback(new ProviderCallback($this->body($params), [], ''), self::CREDENTIALS, 'live');
        $this->assertTrue($tested->testNotification);
        $this->assertSame('test', $driver->verifyCallback(
            new ProviderCallback($this->body($params), [], ''), self::CREDENTIALS, 'test',
        )->environment);
    }

    public function test_unsupported_or_forged_notification_does_not_verify(): void
    {
        $driver = new YooMoneyProvider;
        foreach ([
            ['amount' => '101.00', 'withdraw_amount' => '100.00'],
            ['amount' => '98.00', 'withdraw_amount' => '99.999'],
            ['currency' => '840'],
            ['label' => 'kaevcms-evil'],
            ['codepro' => 'true'],
            ['unaccepted' => 'true'],
            ['test_notification' => 'maybe'],
            ['notification_type' => 'refund'],
        ] as $mutation) {
            $data = array_replace($this->fields(), $mutation);
            try {
                $driver->verifyCallback(new ProviderCallback($this->body($data), [], ''), self::CREDENTIALS, 'live');
                $this->fail('A signed but invalid YooMoney payload passed validation.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
        $body = $this->body($this->fields());
        foreach ([
            str_replace('withdraw_amount=100.00', 'withdraw_amount=100.01', $body),
            $body.'&amount=98.00',
            $body.'&amount%5B0%5D=98.00',
            str_replace('sign=', 'other=', $body),
        ] as $forged) {
            try {
                $driver->verifyCallback(new ProviderCallback($forged, [], ''), self::CREDENTIALS, 'live');
                $this->fail('A forged YooMoney payload passed HMAC verification.');
            } catch (InvalidArgumentException) {
                // Expected.
            }
        }
    }

    public function test_signed_legacy_sha1_field_is_included_but_cannot_replace_hmac(): void
    {
        $canonical = 'amount=98.00&codepro=false&currency=643&datetime=2013-12-26T08%3A28%3A34Z&label=ML23045&notification_type=p2p-incoming&operation_id=441361714955017004&sender=41000000000&sha1_hash=ac13833bd6ba9eff1fa9e4bed76f3d6ebb57f6c0&unaccepted=false&withdraw_amount=100.00';
        $this->assertSame('a452af731650e2c5b39abcdc7c28dd27db7b3b654c2230ad2c386e64afb98605',
            hash_hmac('sha256', $canonical, 'secret123'));
    }
}
