<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KaevCMS\Modules\Voting\Providers\KTreonProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VotingEventApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-2222-4333-8444-555555555555';

    private const ENDPOINT = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events';

    private const CURSOR = '9007199254740993';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
        Http::preventStrayRequests();
    }

    /** @return array<string,mixed> */
    private static function event(): array
    {
        return [
            'cursor' => '9007199254740999',
            'event_id' => '22222222-3333-4444-8555-666666666666',
            'vote_id' => '33333333-4444-4555-8666-777777777777',
            'project_id' => self::PROJECT,
            'player_ref' => str_repeat('a', 64),
            'status' => 'accepted',
            'status_version' => 2,
            'voted_at' => '2026-11-27T00:00:00.1234567Z',
            'status_changed_at' => '2026-11-27T00:01:00+00:00',
        ];
    }

    public function test_read_accepts_cursor_gaps_and_keeps_decimal_precision_without_writes(): void
    {
        Http::fake([self::ENDPOINT.'*' => Http::response([
            'events' => [self::event()], 'next_cursor' => '9007199254740999', 'has_more' => true,
        ])]);
        $key = 'kti_'.str_repeat('a', 43);
        $page = app(KTreonProvider::class)->readEvents(self::PROJECT, $key, self::CURSOR, 1);
        $this->assertSame('ok', $page->result);
        $this->assertTrue($page->hasMore);
        $this->assertSame('9007199254740999', $page->nextCursor);
        $this->assertCount(1, $page->events);
        $this->assertSame('2026-11-27 00:00:00.123456', $page->events[0]->votedAt);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::ENDPOINT.'?cursor='.self::CURSOR.'&limit=1'
            && $request->hasHeader('Authorization', 'Bearer '.$key));
        $this->assertDatabaseHas('module_voting_settings', ['event_cursor' => '0']);
        $this->assertDatabaseCount('module_voting_events', 0);
        $this->assertDatabaseCount('reward_inventory_grants', 0);
    }

    public function test_empty_page_preserves_requested_cursor(): void
    {
        Http::fake([self::ENDPOINT.'*' => Http::response([
            'events' => [], 'next_cursor' => self::CURSOR, 'has_more' => false,
        ])]);
        $page = app(KTreonProvider::class)->readEvents(self::PROJECT, 'kti_'.str_repeat('a', 43), self::CURSOR);
        $this->assertSame('ok', $page->result);
        $this->assertSame(self::CURSOR, $page->nextCursor);
        $this->assertSame([], $page->events);
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function invalidPages(): array
    {
        $event = self::event();
        $page = ['events' => [$event], 'next_cursor' => $event['cursor'], 'has_more' => false];

        return [
            'foreign project' => [array_replace($page, ['events' => [array_replace($event, ['project_id' => $event['vote_id']])]])],
            'unknown status' => [array_replace($page, ['events' => [array_replace($event, ['status' => 'unknown'])]])],
            'nonpositive version' => [array_replace($page, ['events' => [array_replace($event, ['status_version' => 0])]])],
            'numeric cursor' => [array_replace($page, ['events' => [array_replace($event, ['cursor' => 9007199254740999])]])],
            'overflow cursor' => [array_replace($page, ['events' => [array_replace($event, ['cursor' => '9223372036854775808'])], 'next_cursor' => '9223372036854775808'])],
            'stale cursor' => [array_replace($page, ['events' => [array_replace($event, ['cursor' => self::CURSOR])], 'next_cursor' => self::CURSOR])],
            'wrong next cursor' => [array_replace($page, ['next_cursor' => '9007199254741000'])],
            'empty page advances' => [array_replace($page, ['events' => []])],
            'empty object is not a list' => [array_replace($page, ['events' => (object) [], 'next_cursor' => self::CURSOR])],
            'empty continuation' => [array_replace($page, ['events' => [], 'next_cursor' => self::CURSOR, 'has_more' => true])],
            'invalid date' => [array_replace($page, ['events' => [array_replace($event, ['voted_at' => '2026-02-30T00:00:00Z'])]])],
            'duplicate event' => [array_replace($page, ['events' => [$event, array_replace($event, ['cursor' => '9007199254741000'])], 'next_cursor' => '9007199254741000'])],
            'duplicate vote version' => [array_replace($page, ['events' => [$event, array_replace($event, ['cursor' => '9007199254741000', 'event_id' => '44444444-5555-4666-8777-888888888888'])], 'next_cursor' => '9007199254741000'])],
            'recipient changed' => [array_replace($page, ['events' => [$event, array_replace($event, ['cursor' => '9007199254741000', 'event_id' => '44444444-5555-4666-8777-888888888888', 'status_version' => 3, 'player_ref' => str_repeat('b', 64)])], 'next_cursor' => '9007199254741000'])],
        ];
    }

    /** @param array<string,mixed> $payload */
    #[DataProvider('invalidPages')]
    public function test_invalid_page_is_rejected_as_a_whole(array $payload): void
    {
        Http::fake([self::ENDPOINT.'*' => Http::response($payload)]);
        $page = app(KTreonProvider::class)->readEvents(self::PROJECT, 'kti_'.str_repeat('a', 43), self::CURSOR);
        $this->assertSame('invalid_response', $page->result);
        $this->assertNull($page->nextCursor);
        $this->assertSame([], $page->events);
    }

    public function test_read_rate_limit_is_not_a_successful_empty_page(): void
    {
        Http::fake([self::ENDPOINT.'*' => Http::response(['error' => 'rate_limited'], 429, ['Retry-After' => '120'])]);
        $page = app(KTreonProvider::class)->readEvents(self::PROJECT, 'kti_'.str_repeat('a', 43), '0');
        $this->assertSame('rate_limited', $page->result);
        $this->assertSame(120, $page->retryAfter);
        $this->assertNull($page->nextCursor);
    }

    public function test_oversized_page_is_rejected_without_processing(): void
    {
        Http::fake([self::ENDPOINT.'*' => Http::response(str_repeat(' ', 131073), 200, ['Content-Type' => 'application/json'])]);
        $page = app(KTreonProvider::class)->readEvents(self::PROJECT, 'kti_'.str_repeat('a', 43), '0');
        $this->assertSame('invalid_response', $page->result);
        $this->assertSame([], $page->events);
    }
}
