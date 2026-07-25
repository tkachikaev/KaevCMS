<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Exceptions\GameServerHasRewardData;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\RewardInventoryGrant;
use App\Models\RewardInventoryItem;
use App\Models\User;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameServerSettings;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;
use Tests\TestCase;

class PromoCodesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = app(ModuleManager::class);
        $module = $modules->enable('promo-codes');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_bundled_module_is_valid_migrates_and_registers_navigation(): void
    {
        $module = app(ModuleManager::class)->inspect('promo-codes');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['enabled']);
        $this->assertSame([], $module['pending_migrations']);
        $this->assertTrue(Schema::hasColumn('module_promo_codes', 'deleted_at'));
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'promo-codes',
            'version' => '1.0.3',
            'enabled' => true,
        ]);

        $accountLinks = app(ModuleNavigationRegistry::class)->accountLinks();
        $adminLinks = app(ModuleNavigationRegistry::class)->adminLinks();

        $this->assertSame('modules.promo-codes.index', $accountLinks[0]['route'] ?? null);
        $this->assertSame('admin.module-pages.promo-codes.index', $adminLinks[0]['route'] ?? null);
    }

    public function test_authenticated_user_can_open_account_promo_code_page(): void
    {
        $user = User::factory()->create(['name' => 'Promo Player']);

        $this->actingAs($user)
            ->get('/modules/promo-codes')
            ->assertOk()
            ->assertSee('Promo Player')
            ->assertSee(__('module-promo-codes::messages.account_title'))
            ->assertSee('account-section account-surface promo-activation-surface', false)
            ->assertSee('wire:current="active"', false);
    }

    public function test_create_form_starts_with_one_compact_reward_row_and_can_add_more_in_browser(): void
    {
        GameServer::factory()->create(['name' => 'Interlude x5']);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $response = $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/promo-codes/create')
            ->assertOk()
            ->assertSee(__('module-promo-codes::messages.select_server'))
            ->assertSee('data-promo-rewards-editor', false)
            ->assertSee('data-promo-reward-add', false)
            ->assertSee('assets/admin/js/promo-codes.js', false);

        $this->assertSame(2, substr_count($response->getContent(), 'name="rewards['));
        $this->assertStringContainsString('promo-reward-row', $response->getContent());
    }

    public function test_owner_creates_and_updates_server_bound_promo_code_while_administrator_is_read_only(): void
    {
        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->administrator()->create();

        $payload = [
            'code' => 'welcome-2026',
            'game_server_id' => $server->id,
            'starts_at' => '2026-07-21T12:00',
            'ends_at' => '2026-08-21T12:00',
            'total_limit' => 0,
            'per_user_limit' => 1,
            'enabled' => 1,
            'rewards' => [
                ['item_id' => 57, 'amount' => 1000000],
                ['item_id' => 4037, 'amount' => 10],
                ['item_id' => '', 'amount' => ''],
            ],
        ];

        $this->actingAs($administrator, 'admin')
            ->post('/admin/extensions/promo-codes', $payload)
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/promo-codes', $payload)
            ->assertRedirect('/admin/extensions/promo-codes');

        $promoCode = PromoCode::query()->with('rewards')->firstOrFail();
        $this->assertSame('WELCOME-2026', $promoCode->code);
        $this->assertSame($server->id, $promoCode->game_server_id);
        $this->assertSame(0, $promoCode->total_limit);
        $this->assertSame(1, $promoCode->per_user_limit);
        $this->assertTrue($promoCode->enabled);
        $this->assertCount(2, $promoCode->rewards);
        $this->assertDatabaseHas('module_promo_code_rewards', [
            'promo_code_id' => $promoCode->id,
            'item_id' => 57,
            'amount' => 1000000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'promo_code.created',
            'target_id' => (string) $promoCode->id,
        ]);

        $this->actingAs($administrator, 'admin')
            ->get('/admin/extensions/promo-codes/create')
            ->assertForbidden();
        $this->actingAs($administrator, 'admin')
            ->get('/admin/extensions/promo-codes/'.$promoCode->id.'/edit')
            ->assertOk()
            ->assertDontSee('class="button button-primary" type="submit"', false);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/promo-codes/'.$promoCode->id, array_merge($payload, [
                'total_limit' => 50,
                'per_user_limit' => 2,
                'enabled' => 0,
                'rewards' => [['item_id' => 57, 'amount' => 2000000]],
            ]))
            ->assertRedirect('/admin/extensions/promo-codes');

        $promoCode->refresh()->load('rewards');
        $this->assertSame(50, $promoCode->total_limit);
        $this->assertSame(2, $promoCode->per_user_limit);
        $this->assertFalse($promoCode->enabled);
        $this->assertCount(1, $promoCode->rewards);
        $this->assertSame(2000000, $promoCode->rewards->first()->amount);
    }

    public function test_code_must_have_at_least_four_characters_and_reward_item_ids_are_unique(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/promo-codes', [
                'code' => 'abc',
                'game_server_id' => $server->id,
                'starts_at' => '',
                'ends_at' => '',
                'total_limit' => 0,
                'per_user_limit' => 1,
                'enabled' => 1,
                'rewards' => [
                    ['item_id' => 57, 'amount' => 1],
                    ['item_id' => '057', 'amount' => 2],
                ],
            ])
            ->assertSessionHasErrors(['code', 'rewards.1.item_id']);

        $this->assertDatabaseEmpty('module_promo_codes');
    }

    public function test_start_and_end_dates_may_be_configured_independently(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $base = [
            'game_server_id' => $server->id,
            'total_limit' => 0,
            'per_user_limit' => 1,
            'enabled' => 1,
            'rewards' => [['item_id' => 57, 'amount' => 1]],
        ];

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/promo-codes', array_merge($base, [
                'code' => 'ENDONLY',
                'starts_at' => '',
                'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/promo-codes', array_merge($base, [
                'code' => 'STARTONLY',
                'starts_at' => now()->subDay()->format('Y-m-d\TH:i'),
                'ends_at' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('module_promo_codes', ['code' => 'ENDONLY']);
        $this->assertDatabaseHas('module_promo_codes', ['code' => 'STARTONLY']);
    }

    public function test_activation_is_idempotent_and_adds_all_rewards_to_the_bound_server_inventory(): void
    {
        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $otherServer = GameServer::factory()->create(['name' => 'High Five x10']);
        $user = User::factory()->create();
        $promoCode = $this->createPromoCode($server, [
            ['item_id' => 57, 'amount' => 1000000],
            ['item_id' => 4037, 'amount' => 10],
        ]);
        $requestToken = (string) Str::uuid();

        $this->actingAs($user)
            ->post('/modules/promo-codes/activate', [
                'code' => strtolower($promoCode->code),
                'request_token' => $requestToken,
            ])
            ->assertRedirect('/modules/promo-codes')
            ->assertSessionHas('status');

        $this->actingAs($user)
            ->post('/modules/promo-codes/activate', [
                'code' => $promoCode->code,
                'request_token' => $requestToken,
            ])
            ->assertRedirect('/modules/promo-codes');

        $this->assertSame(1, PromoCodeActivation::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->count());
        $this->assertSame(2, RewardInventoryItem::query()->count());
        $this->assertDatabaseHas('reward_inventory_items', [
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'item_id' => 57,
            'amount' => 1000000,
            'status' => 'available',
        ]);
        $this->assertDatabaseMissing('reward_inventory_items', [
            'user_id' => $user->id,
            'game_server_id' => $otherServer->id,
        ]);
        $this->assertDatabaseHas('reward_inventory_grants', [
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'source_type' => 'promo-code',
            'source_reference' => (string) $promoCode->id,
            'source_label' => $promoCode->code,
        ]);
        $this->assertSame(1, $promoCode->fresh()->activations_count);
    }

    public function test_per_account_and_total_limits_are_enforced_and_zero_total_limit_is_unlimited(): void
    {
        $server = GameServer::factory()->create();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $limited = $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]], [
            'code' => 'LIMITED',
            'total_limit' => 1,
            'per_user_limit' => 1,
        ]);

        $this->activate($firstUser, $limited, (string) Str::uuid())->assertSessionHas('status');
        $this->activate($firstUser, $limited, (string) Str::uuid())
            ->assertSessionHasErrors('code');
        $this->activate($secondUser, $limited, (string) Str::uuid())
            ->assertSessionHasErrors('code');

        $this->assertSame(1, PromoCodeActivation::query()->where('promo_code_id', $limited->id)->count());

        $unlimited = $this->createPromoCode($server, [['item_id' => 4037, 'amount' => 1]], [
            'code' => 'UNLIMITED',
            'total_limit' => 0,
            'per_user_limit' => 2,
        ]);

        $this->activate($firstUser, $unlimited, (string) Str::uuid())->assertSessionHas('status');
        $this->activate($firstUser, $unlimited, (string) Str::uuid())->assertSessionHas('status');
        $this->activate($secondUser, $unlimited, (string) Str::uuid())->assertSessionHas('status');

        $this->assertSame(3, PromoCodeActivation::query()->where('promo_code_id', $unlimited->id)->count());
        $this->assertSame(3, $unlimited->fresh()->activations_count);
    }

    public function test_disabled_scheduled_and_expired_codes_do_not_grant_rewards(): void
    {
        $server = GameServer::factory()->create();
        $user = User::factory()->create();

        $codes = [
            $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]], [
                'code' => 'DISABLED',
                'enabled' => false,
            ]),
            $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]], [
                'code' => 'SCHEDULED',
                'starts_at' => now()->addDay(),
            ]),
            $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]], [
                'code' => 'EXPIRED',
                'ends_at' => now()->subMinute(),
            ]),
        ];

        foreach ($codes as $promoCode) {
            $this->activate($user, $promoCode, (string) Str::uuid())
                ->assertSessionHasErrors('code');
        }

        $this->assertDatabaseEmpty('module_promo_code_activations');
        $this->assertDatabaseEmpty('reward_inventory_grants');
        $this->assertDatabaseEmpty('reward_inventory_items');
    }

    public function test_disabling_code_preserves_activation_journal_and_inventory_rewards(): void
    {
        $server = GameServer::factory()->create();
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $promoCode = $this->createPromoCode($server, [['item_id' => 57, 'amount' => 100]]);

        $this->activate($user, $promoCode, (string) Str::uuid())->assertSessionHas('status');

        $this->actingAs($owner, 'admin')
            ->patch('/admin/extensions/promo-codes/'.$promoCode->id.'/toggle')
            ->assertRedirect('/admin/extensions/promo-codes');

        $this->assertFalse($promoCode->fresh()->enabled);
        $this->assertSame(1, PromoCodeActivation::query()->count());
        $this->assertSame(1, RewardInventoryItem::query()->count());
        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/promo-codes/activations')
            ->assertOk()
            ->assertSee($promoCode->code)
            ->assertSee($user->email);
    }

    public function test_used_promo_code_keeps_its_original_server_and_historical_rewards(): void
    {
        $server = GameServer::factory()->create(['name' => 'Interlude x5']);
        $otherServer = GameServer::factory()->create(['name' => 'High Five x10']);
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $promoCode = $this->createPromoCode($server, [['item_id' => 57, 'amount' => 100]]);

        $this->activate($user, $promoCode, (string) Str::uuid())->assertSessionHas('status');

        $this->actingAs($owner, 'admin')
            ->from('/admin/extensions/promo-codes/'.$promoCode->id.'/edit')
            ->put('/admin/extensions/promo-codes/'.$promoCode->id, [
                'code' => $promoCode->code,
                'game_server_id' => $otherServer->id,
                'starts_at' => '',
                'ends_at' => '',
                'total_limit' => 0,
                'per_user_limit' => 1,
                'enabled' => 1,
                'rewards' => [['item_id' => 4037, 'amount' => 1]],
            ])
            ->assertRedirect('/admin/extensions/promo-codes/'.$promoCode->id.'/edit')
            ->assertSessionHasErrors('game_server_id');

        $this->assertSame($server->id, $promoCode->fresh()->game_server_id);

        $promoCode->rewards()->delete();
        $promoCode->rewards()->create(['item_id' => 4037, 'amount' => 1, 'sort_order' => 0]);

        $activation = PromoCodeActivation::query()
            ->with('rewardGrant.items')
            ->firstOrFail();

        $this->assertSame(57, $activation->rewardGrant?->items->first()?->item_id);
        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/promo-codes/activations')
            ->assertOk()
            ->assertSee('Адена × 100')
            ->assertSee('ID 57')
            ->assertDontSee('ID 4037');
    }

    public function test_owner_can_delete_promo_code_without_losing_activation_history_or_granted_rewards(): void
    {
        $server = GameServer::factory()->create();
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->administrator()->create();
        $promoCode = $this->createPromoCode($server, [['item_id' => 57, 'amount' => 100]], [
            'code' => 'DELETE-ME',
        ]);

        $this->activate($user, $promoCode, (string) Str::uuid())->assertSessionHas('status');

        $this->actingAs($administrator, 'admin')
            ->delete('/admin/extensions/promo-codes/'.$promoCode->id)
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->delete('/admin/extensions/promo-codes/'.$promoCode->id)
            ->assertRedirect('/admin/extensions/promo-codes');

        $this->assertSoftDeleted('module_promo_codes', ['id' => $promoCode->id]);
        $this->assertSame(1, PromoCodeActivation::query()->count());
        $this->assertSame(1, RewardInventoryGrant::query()->count());
        $this->assertSame(1, RewardInventoryItem::query()->count());
        $this->assertDatabaseHas('module_promo_code_rewards', [
            'promo_code_id' => $promoCode->id,
            'item_id' => 57,
            'amount' => 100,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'promo_code.deleted',
            'target_id' => (string) $promoCode->id,
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/promo-codes')
            ->assertOk()
            ->assertDontSee('/admin/extensions/promo-codes/'.$promoCode->id.'/edit', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/promo-codes/activations')
            ->assertOk()
            ->assertSee('DELETE-ME')
            ->assertSee('Адена × 100')
            ->assertSee('ID 57');

        $this->activate($user, $promoCode, (string) Str::uuid())
            ->assertSessionHasErrors('code');
    }

    public function test_deleted_unused_promo_code_no_longer_blocks_game_server_deletion(): void
    {
        $server = GameServer::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $promoCode = $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]]);

        $this->actingAs($owner, 'admin')
            ->delete('/admin/extensions/promo-codes/'.$promoCode->id)
            ->assertRedirect('/admin/extensions/promo-codes');

        app(GameServerSettings::class)->delete($server);

        $this->assertDatabaseMissing('game_servers', ['id' => $server->id]);
    }

    public function test_game_server_with_promo_code_history_cannot_be_deleted(): void
    {
        $server = GameServer::factory()->create();
        $this->createPromoCode($server, [['item_id' => 57, 'amount' => 1]]);

        $this->expectException(GameServerHasRewardData::class);

        app(GameServerSettings::class)->delete($server);
    }

    public function test_game_asset_resolver_prefers_server_specific_item_icon_and_falls_back_to_common(): void
    {
        $root = storage_path('framework/testing/game-assets-'.Str::uuid());
        config()->set('cms.game_assets.uploads_path', $root);
        $server = GameServer::factory()->create();
        $resolver = app(GameAssetUrlResolver::class);

        try {
            File::ensureDirectoryExists($root.'/items/common');
            File::put($root.'/items/common/57.webp', 'common');
            $this->assertStringEndsWith('/uploads/game-assets/items/common/57.webp', $resolver->itemIcon($server, 57));

            File::ensureDirectoryExists($root.'/items/servers/'.$server->id);
            File::put($root.'/items/servers/'.$server->id.'/57.png', 'server');
            $this->assertStringEndsWith('/uploads/game-assets/items/servers/'.$server->id.'/57.png', $resolver->itemIcon($server, 57));
            $this->assertNull($resolver->itemIcon($server, 0));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_module_translation_keys_have_full_ru_en_parity_without_case_insensitive_duplicates(): void
    {
        $english = require base_path('modules/promo-codes/lang/en/messages.php');
        $russian = require base_path('modules/promo-codes/lang/ru/messages.php');

        $this->assertSame(array_keys($english), array_keys($russian));
        $this->assertCount(count($english), array_unique(array_map('mb_strtolower', array_keys($english))));
        $this->assertCount(count($russian), array_unique(array_map('mb_strtolower', array_keys($russian))));

        foreach (['main_settings_help', 'server_help', 'server_locked_after_activation', 'validation_server_exists'] as $key) {
            $this->assertStringNotContainsString('GameServer', $russian[$key]);
        }
    }

    /**
     * @param  list<array{item_id:int,amount:int}>  $rewards
     * @param  array<string, mixed>  $attributes
     */
    private function createPromoCode(GameServer $server, array $rewards, array $attributes = []): PromoCode
    {
        $promoCode = PromoCode::query()->create(array_merge([
            'game_server_id' => $server->id,
            'code' => 'WELCOME2026',
            'starts_at' => null,
            'ends_at' => null,
            'total_limit' => 0,
            'per_user_limit' => 1,
            'activations_count' => 0,
            'enabled' => true,
        ], $attributes));

        foreach ($rewards as $index => $reward) {
            $promoCode->rewards()->create([
                'item_id' => $reward['item_id'],
                'amount' => $reward['amount'],
                'sort_order' => $index,
            ]);
        }

        return $promoCode->load(['rewards', 'gameServer.translations']);
    }

    private function activate(User $user, PromoCode $promoCode, string $requestToken): TestResponse
    {
        return $this->actingAs($user)
            ->from('/modules/promo-codes')
            ->post('/modules/promo-codes/activate', [
                'code' => $promoCode->code,
                'request_token' => $requestToken,
            ]);
    }
}
