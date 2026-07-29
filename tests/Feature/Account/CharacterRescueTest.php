<?php

namespace Tests\Feature\Account;

use App\Contracts\CharacterRescueGateway;
use App\Contracts\GameAccountGateway;
use App\Models\CharacterRescueOperation;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Support\GameServerFeatures\CharacterRescueWriteResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeCharacterRescueGateway;
use Tests\Fakes\FakeGameAccountGateway;
use Tests\TestCase;

class CharacterRescueTest extends TestCase
{
    use RefreshDatabase;

    private FakeCharacterRescueGateway $rescueGateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rescueGateway = new FakeCharacterRescueGateway;
        $this->app->instance(CharacterRescueGateway::class, $this->rescueGateway);
    }

    public function test_user_can_return_an_offline_character_without_password_confirmation(): void
    {
        [$user, $server, $account] = $this->context();
        $this->enable($server);

        $this->actingAs($user)
            ->post(route('characters.rescue', [$server, $account, 100]))
            ->assertRedirect()
            ->assertSessionHas('account_operation.type', 'success')
            ->assertSessionHas('account_operation.title', 'Персонаж возвращён в город');

        $this->assertSame(1, $this->rescueGateway->rescueCalls);
        $this->assertSame('PlayerOne', $this->rescueGateway->lastCall['account_login']);
        $this->assertSame(['x' => 83400, 'y' => 148600, 'z' => -3400], $this->rescueGateway->lastCall['target']);
        $this->assertDatabaseHas('character_rescue_operations', [
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'character_id' => 100,
            'status' => CharacterRescueOperation::STATUS_SUCCESS,
            'character_name' => 'Bubi',
        ]);
    }

    public function test_user_cannot_rescue_a_character_through_another_users_game_account(): void
    {
        [$user, $server] = $this->context();
        $otherAccount = UserGameAccount::factory()->registeredOn($server)->create();
        $this->enable($server);

        $this->actingAs($user)
            ->post(route('characters.rescue', [$server, $otherAccount, 100]))
            ->assertRedirect()
            ->assertSessionHas('account_operation.type', 'error');

        $this->assertSame(0, $this->rescueGateway->rescueCalls);
        $this->assertDatabaseCount('character_rescue_operations', 0);
    }

    public function test_online_character_is_not_changed(): void
    {
        [$user, $server, $account] = $this->context();
        $this->enable($server);
        $this->rescueGateway->result = new CharacterRescueWriteResult(
            CharacterRescueWriteResult::ONLINE,
            characterName: 'Bubi',
        );

        $this->actingAs($user)
            ->post(route('characters.rescue', [$server, $account, 100]))
            ->assertRedirect()
            ->assertSessionHas('account_operation.type', 'warning')
            ->assertSessionHas('account_operation.title', 'Персонаж находится в игре');

        $this->assertDatabaseHas('character_rescue_operations', [
            'character_id' => 100,
            'status' => CharacterRescueOperation::STATUS_FAILED,
            'failure_code' => CharacterRescueWriteResult::ONLINE,
        ]);
    }

    public function test_successful_rescue_cooldown_blocks_a_second_write(): void
    {
        [$user, $server, $account] = $this->context();
        $this->enable($server);
        CharacterRescueOperation::query()->create([
            'operation_uuid' => '1b407068-6f82-47e7-957f-7982fb8af100',
            'user_id' => $user->id,
            'game_server_id' => $server->id,
            'user_game_account_id' => $account->id,
            'character_id' => 100,
            'character_name' => 'Bubi',
            'account_login' => $account->game_login,
            'location_name' => 'Giran',
            'target_x' => 83400,
            'target_y' => 148600,
            'target_z' => -3400,
            'status' => CharacterRescueOperation::STATUS_SUCCESS,
            'requested_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post(route('characters.rescue', [$server, $account, 100]))
            ->assertRedirect()
            ->assertSessionHas('account_operation.title', 'Повторный перенос пока недоступен');

        $this->assertSame(0, $this->rescueGateway->rescueCalls);
    }

    public function test_character_card_does_not_show_rescue_button_when_feature_is_disabled(): void
    {
        [$user, $server] = $this->context();
        $gameAccounts = new FakeGameAccountGateway;
        $gameAccounts->charactersByServer[$server->id] = [[
            'id' => 100,
            'name' => 'Bubi',
            'level' => 80,
            'class_id' => 88,
            'race' => 0,
            'gender' => 1,
            'online' => false,
            'last_access' => CarbonImmutable::now()->subHour()->getTimestamp() * 1000,
        ]];
        $this->app->instance(GameAccountGateway::class, $gameAccounts);

        $this->actingAs($user)
            ->get(route('characters.index'))
            ->assertOk()
            ->assertDontSee('data-character-rescue-open', false);
    }

    public function test_offline_character_card_shows_rescue_button_when_feature_is_enabled(): void
    {
        [$user, $server] = $this->context();
        $this->enable($server);
        $gameAccounts = new FakeGameAccountGateway;
        $gameAccounts->charactersByServer[$server->id] = [[
            'id' => 100,
            'name' => 'Bubi',
            'level' => 80,
            'class_id' => 88,
            'race' => 0,
            'gender' => 1,
            'online' => false,
            'last_access' => CarbonImmutable::now()->subHour()->getTimestamp() * 1000,
        ]];
        $this->app->instance(GameAccountGateway::class, $gameAccounts);

        $this->actingAs($user)
            ->get(route('characters.index'))
            ->assertOk()
            ->assertSee('Вернуть в город')
            ->assertSee('data-character-rescue-open', false)
            ->assertSee('data-character-rescue-online="0"', false);
    }

    public function test_online_character_card_keeps_rescue_button_and_explains_that_logout_is_required(): void
    {
        [$user, $server] = $this->context();
        $this->enable($server);
        $gameAccounts = new FakeGameAccountGateway;
        $gameAccounts->charactersByServer[$server->id] = [[
            'id' => 100,
            'name' => 'Bubi',
            'level' => 80,
            'class_id' => 88,
            'race' => 0,
            'gender' => 1,
            'online' => true,
            'last_access' => CarbonImmutable::now()->subMinute()->getTimestamp() * 1000,
        ]];
        $this->app->instance(GameAccountGateway::class, $gameAccounts);

        $this->actingAs($user)
            ->get(route('characters.index'))
            ->assertOk()
            ->assertSee('Вернуть в город')
            ->assertSee('data-character-rescue-open', false)
            ->assertSee('data-character-rescue-online="1"', false)
            ->assertSee('Персонаж должен быть вне игры')
            ->assertSee('Выйдите из игры, дождитесь статуса «Не в игре» и нажмите кнопку ещё раз.');
    }

    /** @return array{User,GameServer,UserGameAccount} */
    private function context(): array
    {
        $user = User::factory()->create();
        $server = GameServer::factory()->create();
        $account = UserGameAccount::factory()
            ->for($user)
            ->registeredOn($server)
            ->create([
                'game_login' => 'PlayerOne',
                'normalized_login' => 'playerone',
            ]);

        return [$user, $server, $account];
    }

    private function enable(GameServer $server): void
    {
        app(GameServerFeatureSettings::class)->updateCharacterRescue($server, [
            'enabled' => true,
            'location_name' => 'Giran',
            'x' => 83400,
            'y' => 148600,
            'z' => -3400,
            'offline_delay_minutes' => 5,
            'cooldown_hours' => 12,
        ]);
    }
}
