<?php

// Destructive acceptance checks; disposable MySQL/MariaDB test databases only.
use App\Contracts\GameAccountGateway;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use KaevCMS\Modules\Referrals\Models\ReferralCode;
use KaevCMS\Modules\Referrals\Models\ReferralEntitlement;
use KaevCMS\Modules\Referrals\Models\ReferralInvite;
use KaevCMS\Modules\Referrals\Models\ReferralProgress;
use KaevCMS\Modules\Referrals\Models\ReferralRule;
use KaevCMS\Modules\Referrals\Models\ReferralServerSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSetting;
use KaevCMS\Modules\Referrals\Models\ReferralSnapshot;
use KaevCMS\Modules\Referrals\Services\ReferralLevelScanner;
use KaevCMS\Modules\Referrals\Services\ReferralRewardGrantService;
use Tests\Fakes\FakeGameAccountGateway;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
});
if (config('app.env') !== 'testing' || DB::connection()->getDriverName() !== 'mysql'
    || ! str_contains((string) DB::connection()->getDatabaseName(), 'referrals_test')
    || getenv('REFERRALS_TEST_ALLOW_CONCURRENCY') !== '1') {
    throw new RuntimeException('Refusing to run outside an explicitly authorized referrals_test database.');
}

if (($argv[1] ?? '') === '--scan-worker') {
    $gateway = new FakeGameAccountGateway;
    $gateway->charactersByServer[(int) $argv[3]] = [['level' => 80]];
    $app->instance(GameAccountGateway::class, $gateway);
    echo app(ReferralLevelScanner::class)->scan(
        ReferralInvite::query()->findOrFail((int) $argv[2]),
        GameServer::query()->findOrFail((int) $argv[3]),
        true,
    );
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    echo app(ReferralRewardGrantService::class)->grantEntitlement((int) $argv[2]);
    exit(0);
}

function ensure(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<int> $ids */
function race(array $ids, ?int $scanServerId = null): void
{
    $workers = [];
    foreach ($ids as $id) {
        $pipes = [];
        $command = $scanServerId === null ? [PHP_BINARY, __FILE__, '--worker', (string) $id]
            : [PHP_BINARY, __FILE__, '--scan-worker', (string) $id, (string) $scanServerId];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 3));
        ensure(is_resource($process), 'Worker did not start.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        ensure(proc_close($process) === 0, 'Worker failed: '.$err);
        ensure(in_array(trim($out), ['granted', 'already_granted', 'deferred', 'updated', 'completed', 'busy'], true), 'Invalid worker result: '.$out);
    }
}

function entitlement(User $inviter, GameServer $server): ReferralEntitlement
{
    $invitee = User::factory()->create();
    $code = ReferralCode::query()->firstOrCreate(['user_id' => $inviter->id], ['code' => Str::random(40)]);
    $invite = ReferralInvite::query()->create([
        'referral_code_id' => $code->id, 'inviter_user_id' => $inviter->id,
        'invitee_user_id' => $invitee->id, 'accepted_at' => now(),
    ]);
    $snapshot = ReferralSnapshot::query()->create([
        'invite_id' => $invite->id, 'game_server_id' => $server->id, 'snapshotted_at' => now(),
    ]);
    ReferralProgress::query()->create([
        'invite_id' => $invite->id, 'game_server_id' => $server->id, 'max_seen_level' => 80, 'status' => 'completed',
    ]);

    return ReferralEntitlement::query()->create([
        'snapshot_id' => $snapshot->id, 'invite_id' => $invite->id, 'game_server_id' => $server->id,
        'recipient_user_id' => $inviter->id, 'audience' => 'inviter', 'level' => 20,
        'item_id' => 57, 'amount' => 3, 'status' => 'earned', 'earned_at' => now(),
    ]);
}

Artisan::call('migrate:fresh', ['--force' => true]);
$module = app(ModuleManager::class)->enable('referrals');
app(ModuleRuntime::class)->bootModule($module);
ReferralSetting::query()->where('setting_key', 'global')->update(['enabled' => true]);
$server = GameServer::factory()->create();
$settings = ReferralServerSetting::query()->create([
    'game_server_id' => $server->id, 'enabled' => true,
    'inviter_rewards_enabled' => true, 'invitee_rewards_enabled' => true,
]);
$inviter = User::factory()->create();
$one = entitlement($inviter, $server);
race(array_fill(0, 10, $one->id));
ensure(DB::table('reward_inventory_grants')->count() === 1, 'Double grant.');
ensure(DB::table('reward_inventory_items')->sum('amount') == 3, 'Double inventory amount.');
ensure($one->fresh()->status === 'granted', 'Entitlement not settled.');
echo "PASS 10 concurrent workers and inventory exactly once\n";
race(array_fill(0, 5, $one->id));
ensure(DB::table('reward_inventory_grants')->count() === 1, 'Replay created a second grant.');
echo "PASS delayed callback/command replay\n";
$limitedInviter = User::factory()->create();
$two = entitlement($limitedInviter, $server);
$three = entitlement($limitedInviter, $server);
$settings->update(['max_rewarded_invitees' => 1]);
race([$two->id, $three->id, $two->id, $three->id]);
ensure(ReferralEntitlement::query()->whereIn('id', [$two->id, $three->id])->where('status', 'granted')->count() === 1, 'Optional cap was exceeded.');
echo "PASS concurrent optional inviter cap\n";
$settings->update(['max_rewarded_invitees' => null]);
$frozen = entitlement(User::factory()->create(), $server);
ReferralInvite::query()->whereKey($frozen->invite_id)->update(['status' => 'frozen']);
race([$frozen->id, $frozen->id]);
ensure($frozen->fresh()->status === 'earned', 'Frozen invitation was rewarded.');
ReferralInvite::query()->whereKey($frozen->invite_id)->update(['status' => 'active']);
race([$frozen->id, $frozen->id]);
ensure($frozen->fresh()->status === 'granted', 'Unfrozen invitation could not resume.');
echo "PASS freeze and idempotent resume\n";
$count = ReferralEntitlement::query()->count();
Artisan::call('migrate', ['--force' => true]);
ensure(ReferralEntitlement::query()->count() === $count, 'Upgrade migration lost history.');
echo "PASS repeat core migration preserves referral history\n";

$scannerInviter = User::factory()->create();
$scannerInvitee = User::factory()->create();
$scannerCode = ReferralCode::query()->create(['user_id' => $scannerInviter->id, 'code' => Str::random(40)]);
$scannerInvite = ReferralInvite::query()->create([
    'referral_code_id' => $scannerCode->id, 'inviter_user_id' => $scannerInviter->id,
    'invitee_user_id' => $scannerInvitee->id, 'accepted_at' => now()->subHour(),
]);
UserGameAccount::factory()->registeredOn($server)->create([
    'user_id' => $scannerInvitee->id, 'creation_completed_at' => now(),
    'creation_status' => UserGameAccount::STATUS_ACTIVE, 'created_via_cms' => true,
]);
ReferralRule::query()->create([
    'game_server_id' => $server->id, 'audience' => 'inviter', 'level' => 20,
    'item_id' => 57, 'amount' => 7, 'enabled' => true,
]);
race(array_fill(0, 6, $scannerInvite->id), $server->id);
ensure(ReferralSnapshot::query()->where('invite_id', $scannerInvite->id)->count() === 1, 'Duplicate scanner snapshots.');
$earned = ReferralEntitlement::query()->where('invite_id', $scannerInvite->id)->sole();
ensure($earned->status === 'earned', 'Scanner did not earn the milestone.');
race(array_fill(0, 6, $earned->id));
ensure(DB::table('reward_inventory_grants')->where('grant_key', 'referrals.entitlement.'.$earned->id)->count() === 1, 'Scanned milestone granted twice.');
echo "PASS concurrent scans create one immutable snapshot and one inventory grant\n";
