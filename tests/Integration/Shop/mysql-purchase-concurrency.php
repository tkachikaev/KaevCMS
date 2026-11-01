<?php

/**
 * Destructive, opt-in MySQL/MariaDB test of independent concurrent purchases.
 * APP_ENV=testing DB_DATABASE=shop_test SHOP_TEST_ALLOW_CONCURRENCY=1 \
 *   SCHEMA_TEST_ALLOW=1 php tests/Integration/Shop/mysql-purchase-concurrency.php
 * Creates its own schema using migrate:fresh. NEVER run on production data.
 */

use App\Exceptions\WalletOperationException;
use App\Models\GameServer;
use App\Models\User;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Shop\Models\ShopCategory;
use KaevCMS\Modules\Shop\Models\ShopProduct;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;
use KaevCMS\Modules\Shop\Models\ShopTab;
use KaevCMS\Modules\Shop\Services\ShopPurchaseService;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
    exit(1);
});

$database = (string) DB::connection()->getDatabaseName();
if (config('app.env') !== 'testing' || DB::connection()->getDriverName() !== 'mysql'
    || preg_match('/(?:shop_test|shop_testing)/i', $database) !== 1
    || getenv('SHOP_TEST_ALLOW_CONCURRENCY') !== '1'
    || getenv('SCHEMA_TEST_ALLOW') !== '1') {
    throw new RuntimeException('Refusing to run: requires APP_ENV=testing and an explicitly authorized shop_test MySQL database.');
}

if (($argv[1] ?? '') === '--worker') {
    $user = User::query()->findOrFail((int) ($argv[2] ?? 0));
    $productId = (int) ($argv[3] ?? 0);
    $uuid = (string) ($argv[4] ?? '');
    $start = (float) ($argv[5] ?? 0);
    Auth::guard('web')->setUser($user);
    while (microtime(true) < $start) {
        usleep(2000);
    }
    try {
        app(ShopPurchaseService::class)->purchase($user, $productId, $uuid);
        echo "purchased\n";
        exit(0);
    } catch (WalletOperationException $error) {
        if ($error->reason === 'insufficient_funds') {
            echo "insufficient_funds\n";
            exit(0);
        }
        throw $error;
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() === 'Purchase UUID already belongs to another operation.') {
            echo "uuid_conflict\n";
            exit(0);
        }
        throw $error;
    }
}

function requireShop(bool $assertion, string $message): void
{
    if (! $assertion) {
        throw new RuntimeException($message);
    }
}

/** @param list<array{user_id:int,product_id:int,uuid:string}> $jobs
 * @return list<string>
 */
function raceShopJobs(array $jobs): array
{
    $start = microtime(true) + 1.5;
    $workers = [];
    foreach ($jobs as $job) {
        $pipes = [];
        $command = [PHP_BINARY, __FILE__, '--worker', (string) $job['user_id'], (string) $job['product_id'], $job['uuid'], (string) $start];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        requireShop(is_resource($process), 'Unable to launch shop purchase worker.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        $out = trim(stream_get_contents($pipes[1]));
        $err = trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        requireShop(proc_close($process) === 0, 'Shop purchase worker failed: '.$err);
        requireShop(in_array($out, ['purchased', 'insufficient_funds', 'uuid_conflict'], true), 'Invalid result: '.$out);
        $outcomes[] = $out;
    }

    return $outcomes;
}

/** @param list<string> $uuids
 * @return list<string>
 */
function raceShop(int $userId, int $productId, array $uuids): array
{
    return raceShopJobs(array_map(static fn (string $uuid): array => [
        'user_id' => $userId, 'product_id' => $productId, 'uuid' => $uuid,
    ], $uuids));
}

Artisan::call('migrate:fresh', ['--force' => true]);
$module = app(ModuleManager::class)->enable('shop');
app(ModuleRuntime::class)->bootModule($module);
ShopSetting::query()->where('setting_key', 'global')->update(['sales_enabled' => true]);
$server = GameServer::factory()->create();
ShopServerSetting::query()->create(['game_server_id' => $server->id, 'enabled' => true]);
$tab = ShopTab::query()->create([
    'game_server_id' => $server->id, 'name_ru' => 'Товары', 'name_en' => 'Goods', 'sort_order' => 0, 'is_active' => true,
]);
$category = ShopCategory::query()->create([
    'tab_id' => $tab->id, 'name_ru' => 'Предметы', 'name_en' => 'Items', 'sort_order' => 0, 'is_active' => true,
]);
$product = ShopProduct::query()->create([
    'category_id' => $category->id, 'type' => 'item', 'name_ru' => 'Меч', 'name_en' => 'Sword',
    'price_coins' => 25, 'item_id' => 57, 'quantity' => 3, 'sort_order' => 0, 'is_active' => true,
]);
$user = User::factory()->create();
app(WalletTransactionService::class)->credit($user, 100, 'manual', 'shop-concurrency-seed-'.Str::uuid());

// Ten independent connections, one UUID: exactly one debit, grant and purchase.
$uuid = (string) Str::uuid();
$outcomes = raceShop($user->id, $product->id, array_fill(0, 10, $uuid));
requireShop(count(array_filter($outcomes, fn ($outcome) => $outcome === 'purchased')) === 10, 'Same-key replay did not return success.');
requireShop(DB::table('module_shop_purchases')->count() === 1, 'Duplicate purchases for one UUID.');
requireShop(DB::table('wallet_entries')->where('source', 'shop')->count() === 1, 'Duplicate wallet debits.');
requireShop(DB::table('reward_inventory_grants')->where('source_type', 'shop')->count() === 1, 'Duplicate inventory grants.');
requireShop((int) DB::table('wallets')->where('user_id', $user->id)->value('balance') === 75, 'Incorrect balance after replay race.');
echo "PASS 10 identical UUID requests -> one debit and grant\n";

// Different UUIDs compete for a finite balance. Exactly three more can complete.
$outcomes = raceShop($user->id, $product->id, array_map(fn () => (string) Str::uuid(), range(1, 6)));
requireShop(count(array_filter($outcomes, fn ($outcome) => $outcome === 'purchased')) === 3, 'Concurrent different-key purchases exceeded balance.');
requireShop(count(array_filter($outcomes, fn ($outcome) => $outcome === 'insufficient_funds')) === 3, 'Expected three insufficient-funds responses.');
requireShop(DB::table('module_shop_purchases')->count() === 4, 'Unexpected successful purchase count.');
requireShop(DB::table('wallet_entries')->where('source', 'shop')->count() === 4, 'Unexpected debit count.');
requireShop(DB::table('reward_inventory_grants')->where('source_type', 'shop')->count() === 4, 'Unexpected grant count.');
requireShop((int) DB::table('reward_inventory_items')->where('user_id', $user->id)->sum('amount') === 12, 'Unexpected issued item quantity.');
requireShop((int) DB::table('wallets')->where('user_id', $user->id)->value('balance') === 0, 'Wallet overspent.');
echo "PASS 6 distinct concurrent UUIDs, only 3 remaining affordable; no overspending\n";

// Two different players race the same UUID: the unique purchase identity cannot
// be claimed twice, and the losing player must not lose coins or receive items.
$firstBuyer = User::factory()->create();
$secondBuyer = User::factory()->create();
app(WalletTransactionService::class)->credit($firstBuyer, 25, 'manual', 'shop-race-a-'.Str::uuid());
app(WalletTransactionService::class)->credit($secondBuyer, 25, 'manual', 'shop-race-b-'.Str::uuid());
$sharedUuid = (string) Str::uuid();
$outcomes = raceShopJobs([
    ['user_id' => $firstBuyer->id, 'product_id' => $product->id, 'uuid' => $sharedUuid],
    ['user_id' => $secondBuyer->id, 'product_id' => $product->id, 'uuid' => $sharedUuid],
]);
requireShop(count(array_filter($outcomes, fn ($outcome) => $outcome === 'purchased')) === 1, 'Same UUID across users must have one winner.');
requireShop(count(array_filter($outcomes, fn ($outcome) => $outcome === 'uuid_conflict')) === 1, 'Same UUID across users must reject the loser.');
requireShop(DB::table('module_shop_purchases')->where('request_uuid', $sharedUuid)->count() === 1, 'Shared UUID has multiple purchases.');
requireShop(DB::table('wallet_entries')->where('source', 'shop')->count() === 5, 'Cross-user UUID race debited twice.');
requireShop(DB::table('reward_inventory_grants')->where('source_type', 'shop')->count() === 5, 'Cross-user UUID race granted twice.');
$balances = [
    (int) DB::table('wallets')->where('user_id', $firstBuyer->id)->value('balance'),
    (int) DB::table('wallets')->where('user_id', $secondBuyer->id)->value('balance'),
];
sort($balances);
requireShop($balances === [0, 25], 'Losing buyer wallet was modified.');
echo "PASS cross-user identical UUID race -> one debit, one grant, one rejected request\n";

// Re-applying the supported upgrade path must preserve settings and settled data.
Artisan::call('migrate', ['--force' => true]);
app(ModuleManager::class)->enable('shop');
requireShop(DB::table('module_shop_purchases')->count() === 5, 'Upgrade lost purchase history.');
requireShop(DB::table('wallet_entries')->where('source', 'shop')->count() === 5, 'Upgrade changed wallet history.');
requireShop(DB::table('reward_inventory_grants')->where('source_type', 'shop')->count() === 5, 'Upgrade changed inventory history.');
requireShop(ShopSetting::query()->where('setting_key', 'global')->value('sales_enabled') === true, 'Upgrade changed sales settings.');
echo "PASS repeated CMS and module migrations preserve purchases, wallet, inventory and settings\n";
