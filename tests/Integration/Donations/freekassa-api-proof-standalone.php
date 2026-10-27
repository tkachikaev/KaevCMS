<?php

/** Pure PHP parser tests, no Laravel/vendor or live network required. */

declare(strict_types=1);

$base = dirname(__DIR__, 3).'/modules/donations/src/';
require_once $base.'Providers/FreeKassaSignedNotification.php';
require_once $base.'Services/FreeKassaOrderApi.php';

use KaevCMS\Modules\Donations\Services\FreeKassaOrderApi;

$uuid = '11111111-2222-4333-8444-555555555555';
function proof(string $raw): void
{
    global $uuid;
    FreeKassaOrderApi::assertPaidResponse($raw, $uuid, '888999', 10012, 'RUB');
}
function rejectProof(string $raw, string $reason): void
{
    try {
        proof($raw);
    } catch (\RuntimeException) {
        return;
    }
    throw new \RuntimeException('Accepted invalid proof: '.$reason);
}
$baseJson = '{"type":"success","pages":1,"orders":[{"merchant_order_id":"'.$uuid.'","fk_order_id":888999,"amount":100.12,"currency":"RUB","status":1}]}';
proof($baseJson);
rejectProof(str_replace('100.12', '100.120', $baseJson), 'excess monetary precision');
rejectProof(str_replace('"status":1', '"status":0', $baseJson), 'unpaid');
rejectProof(str_replace('"currency":"RUB"', '"currency":"USD"', $baseJson), 'different currency');
rejectProof(str_replace('100.12', '100.13', $baseJson), 'different amount');
rejectProof(str_replace('888999', '999888', $baseJson), 'different operation');
rejectProof(str_replace($uuid, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $baseJson), 'different order');
rejectProof(str_replace('"status":1', '"status":6', $baseJson), 'refunded');
rejectProof(str_replace('"type":"success"', '"type":"error"', $baseJson), 'provider error');
rejectProof(str_replace('100.12', '1e2', $baseJson), 'scientific notation not accepted');
rejectProof('{"type":"success","orders":[]}', 'missing order');
rejectProof('{"type":"success","orders":['.substr($baseJson, strpos($baseJson, '[{')+1, -2).','.substr($baseJson, strpos($baseJson, '[{')+1,-2).']}', 'ambiguous duplicate');
echo "FreeKassa authenticated API response parser: OK\n";
