<?php

namespace Tests\Feature\Modules;

use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Http\Request;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\FreeKassaNotificationTransport;
use Tests\TestCase;

final class DonationFreeKassaTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DonationsTrustedAutoload::register();
    }

    private function parsedMultipart(array $fields, string $length = '1024'): Request
    {
        return Request::create('/payments/freekassa/notify', 'POST', $fields, [], [], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=KaevMultipart',
            'CONTENT_LENGTH' => $length,
        ], '');
    }

    public function test_php_parsed_multipart_preserves_one_authoritative_map_and_empty_fields(): void
    {
        $fields = ['MERCHANT_ID' => '7012', 'AMOUNT' => '120.00', 'P_EMAIL' => ''];
        $this->assertSame('', $this->parsedMultipart($fields)->getContent());
        $this->assertSame(http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            FreeKassaNotificationTransport::decode($this->parsedMultipart($fields)));
    }

    public function test_php_parsed_multipart_rejects_arrays_and_oversized_values(): void
    {
        foreach ([['AMOUNT' => ['120.00']], ['AMOUNT' => str_repeat('1', 2049)], array_fill_keys(range(1, 34), 'value')] as $fields) {
            try {
                FreeKassaNotificationTransport::decode($this->parsedMultipart($fields));
                $this->fail('Invalid parsed multipart was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_php_parsed_multipart_requires_a_bounded_server_content_length(): void
    {
        foreach (['', '0', '16385', '-1', 'invalid'] as $length) {
            try {
                FreeKassaNotificationTransport::decode($this->parsedMultipart(['AMOUNT' => '120.00'], $length));
                $this->fail('Invalid content length was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_multipart_does_not_merge_query_fields_or_fallback_from_malformed_raw_body(): void
    {
        $request = $this->parsedMultipart(['AMOUNT' => '120.00']);
        $request->server->set('QUERY_STRING', 'AMOUNT=1.00');
        $this->expectException(InvalidArgumentException::class);
        FreeKassaNotificationTransport::decode($request);
    }

    public function test_malformed_nonempty_raw_body_cannot_be_replaced_by_parsed_fields(): void
    {
        $request = $this->parsedMultipart(['AMOUNT' => '120.00']);
        $request->initialize([], ['AMOUNT' => '120.00'], [], [], [], $request->server->all(), 'malformed');
        $this->expectException(InvalidArgumentException::class);
        FreeKassaNotificationTransport::decode($request);
    }
}
