<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\PlayerNotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PlayerNotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_notifications_merge_until_read(): void
    {
        $center = app(PlayerNotificationCenter::class);
        $player = User::factory()->create();

        $center->notify($player, 'test.reply', 'Notifications', ['name' => 'A'], '/forum/topics/1#post-2', 'topic:1');
        $center->notify($player, 'test.reply', 'Notifications', ['name' => 'B'], '/forum/topics/1#post-3', 'topic:1');
        $this->assertSame(1, UserNotification::query()->count());
        $merged = UserNotification::query()->firstOrFail();
        $this->assertSame(2, $merged->occurrences);
        $this->assertSame('/forum/topics/1#post-3', $merged->url);
        $this->assertSame(1, $center->unreadCount($player));

        $this->assertSame('/forum/topics/1#post-3', $center->open($player, $merged->id));
        $center->notify($player, 'test.reply', 'Notifications', ['name' => 'C'], '/forum/topics/1#post-4', 'topic:1');
        $this->assertSame(2, UserNotification::query()->count());
        $this->assertSame(1, $center->unreadCount($player));
    }

    public function test_only_internal_paths_and_clean_parameters_are_stored(): void
    {
        $center = app(PlayerNotificationCenter::class);
        $player = User::factory()->create();

        foreach (['https://evil.example/', '//evil.example/x', '/\\evil', 'javascript:alert(1)', "/ok\nnext", ''] as $url) {
            try {
                $center->notify($player, 'test', 'Notifications', [], $url);
                $this->fail('Accepted '.$url);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $stored = $center->notify($player, 'test', 'Notifications', [
            'name' => str_repeat('x', 500), 'count' => 999, 'Bad-Key' => 'x', 'topic' => '<b>Topic</b>',
        ], '/forum');
        $this->assertSame(120, mb_strlen($stored->parameters['name']));
        $this->assertArrayNotHasKey('count', $stored->parameters);
        $this->assertArrayNotHasKey('Bad-Key', $stored->parameters);
        $this->assertSame('<b>Topic</b>', $stored->parameters['topic']);
    }

    public function test_players_open_and_clear_only_their_own_notifications(): void
    {
        $center = app(PlayerNotificationCenter::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notification = $center->notify($owner, 'test', 'Notifications', [], '/forum/topics/5');

        $this->post('/notifications/'.$notification->id)->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $this->getJson('/notifications/count')->assertUnauthorized();

        $this->actingAs($other, 'web');
        $this->post('/notifications/'.$notification->id)->assertRedirect();
        $this->assertNull($notification->fresh()->read_at);
        $this->getJson('/notifications/count')->assertOk()->assertExactJson(['unread' => 0]);

        $this->actingAs($owner, 'web');
        $this->getJson('/notifications/count')->assertOk()->assertExactJson(['unread' => 1]);
        $this->post('/notifications/'.$notification->id)->assertRedirect('/forum/topics/5');
        $this->assertNotNull($notification->fresh()->read_at);

        $center->notify($owner, 'test', 'Notifications', [], '/forum');
        $center->notify($owner, 'test', 'Notifications', [], '/forum');
        $this->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $center->unreadCount($owner));
    }

    public function test_cleanup_removes_old_read_and_very_old_unread_notifications(): void
    {
        $center = app(PlayerNotificationCenter::class);
        $player = User::factory()->create();
        $oldRead = $center->notify($player, 'test', 'Notifications', [], '/a');
        $oldRead->forceFill(['read_at' => now()->subDays(61)])->save();
        $recentRead = $center->notify($player, 'test', 'Notifications', [], '/b');
        $recentRead->forceFill(['read_at' => now()->subDays(5)])->save();
        $ancientUnread = $center->notify($player, 'test', 'Notifications', [], '/c');
        $ancientUnread->forceFill(['occurred_at' => now()->subDays(181)])->save();
        $freshUnread = $center->notify($player, 'test', 'Notifications', [], '/d');

        $this->assertSame(2, $center->cleanup());
        $this->assertSame([$recentRead->id, $freshUnread->id], UserNotification::query()->orderBy('id')->pluck('id')->all());
    }
}
