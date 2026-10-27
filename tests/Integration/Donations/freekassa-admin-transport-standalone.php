<?php

// No framework vendor required: fake only the two HTTP Request methods used by the parser.

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private string $body, private string $type, private ?string $query = null) {}

        public function getContent(): string
        {
            return $this->body;
        }

        public function header(string $key): string
        {
            return $this->type;
        }

        public function getQueryString(): ?string
        {
            return $this->query;
        }
    }
}

namespace {
    use Illuminate\Http\Request;
    use KaevCMS\Modules\Donations\Services\FreeKassaNotificationTransport;

    require_once __DIR__.'/../../../modules/donations/src/Services/FreeKassaNotificationTransport.php';
    $class = FreeKassaNotificationTransport::class;
    $raw = 'MERCHANT_ID=1&AMOUNT=1.00&SIGN=aabb';
    assert($class::decode(new Request($raw, 'application/x-www-form-urlencoded')) === $raw);
    $boundary = 'KaevTest_123';
    $part = static fn (string $key, string $value): string => '--'.$boundary."\r\nContent-Disposition: form-data; name=\"".$key."\"\r\n\r\n".$value."\r\n";
    $body = $part('MERCHANT_ID', '123').$part('AMOUNT', '1.00').$part('SIGN', 'abc').'--'.$boundary.'--';
    assert($class::decode(new Request($body, 'multipart/form-data; boundary='.$boundary)) ===
        'MERCHANT_ID=123&AMOUNT=1.00&SIGN=abc');
    foreach ([
        $part('AMOUNT', '1').$part('AMOUNT', '2').'--'.$boundary.'--',
        $part('AMOUNT', '1').$part('AMOUNT', '2').'--'.$boundary.'--'.'extra',
    ] as $invalid) {
        $rejected = false;
        try {
            $class::decode(new Request($invalid, 'multipart/form-data; boundary='.$boundary));
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        assert($rejected);
    }
    echo "FreeKassa admin test transport: OK\n";
}
