<?php

namespace KaevCMS\Modules\Voting\Services;

use App\Models\ModuleState;
use App\Support\Modules\ModuleManager;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use KaevCMS\Modules\Voting\Data\VoteEventPage;
use KaevCMS\Modules\Voting\Models\VotingBinding;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use RuntimeException;
use Throwable;

final class VotingSyncService
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly VotingProviderRegistry $providers,
        private readonly VotingEventProcessor $processor,
    ) {}

    /** @return array{result:string,processed:int,granted:int,pages:int} */
    public function sync(int $maxPages = 4, int $pageSize = 25): array
    {
        $counts = ['result' => 'idle', 'processed' => 0, 'granted' => 0, 'pages' => 0];
        if ($this->modules->inspect('voting')['status'] !== 'enabled') {
            return $counts;
        }

        $pageSize = max(1, min(25, $pageSize));
        $deadline = hrtime(true) + 45_000_000_000;
        for ($i = 0; $i < max(1, min(4, $maxPages)) && hrtime(true) < $deadline; $i++) {
            $setting = VotingSetting::query()->where('provider_code', 'ktreon')->first();
            if (! $this->moduleEnabled() || $setting === null || $setting->project_id === null
                || $setting->api_key_ciphertext === null) {
                return $counts;
            }
            // Pausing invitations does not abandon votes already made using issued references.
            if (! VotingBinding::query()->where('project_id', $setting->project_id)->exists()
                && $setting->event_cursor === '0') {
                return $counts;
            }
            if ($setting->sync_retry_at?->isFuture()) {
                $counts['result'] = 'cooldown';

                return $counts;
            }

            $project = $setting->project_id;
            $cursor = $setting->event_cursor;
            $revision = $setting->credential_revision;
            try {
                $key = Crypt::decryptString($setting->api_key_ciphertext);
                // Network I/O is outside the transaction. The database row provides the commit fence.
                $page = $this->providers->get('ktreon')->readEvents($project, $key, $cursor, $pageSize);
                unset($key);
            } catch (DecryptException) {
                $page = new VoteEventPage('not_configured');
            }
            if ($page->result !== 'ok') {
                $counts['result'] = $this->recordFailure($project, $revision, $cursor, $page->result, $page->retryAfter);

                return $counts;
            }

            try {
                $committed = DB::transaction(function () use ($project, $revision, $cursor, $page, $pageSize): ?array {
                    $current = $this->current($project, $revision, $cursor);
                    if ($current === null) {
                        return null;
                    }
                    if ($page->nextCursor === null || ! VoteEventDecoder::validCursor($page->nextCursor)
                        || count($page->events) > $pageSize || ($page->hasMore && count($page->events) !== $pageSize)) {
                        throw new RuntimeException('Invalid voting page.');
                    }
                    $last = $cursor;
                    $processed = 0;
                    $granted = 0;
                    foreach ($page->events as $event) {
                        if ($event->projectId !== $project || ! VoteEventDecoder::validCursor($event->cursor)
                            || VoteEventDecoder::compareCursor($event->cursor, $last) <= 0) {
                            throw new RuntimeException('Invalid voting event order.');
                        }
                        $result = $this->processor->apply($event, $current->pending_policy);
                        $processed += $result['processed'];
                        $granted += $result['granted'];
                        $last = $event->cursor;
                    }
                    if ($last !== $page->nextCursor) {
                        throw new RuntimeException('Invalid voting next cursor.');
                    }
                    $current->forceFill([
                        'event_cursor' => $last,
                        'last_sync_at' => now(),
                        'last_sync_result' => 'ok',
                        'sync_retry_at' => null,
                    ])->save();

                    return ['processed' => $processed, 'granted' => $granted];
                }, 3);
            } catch (Throwable $exception) {
                // No raw payload, exception message, HTTP response or credential in logs.
                Log::warning('Voting page rolled back.', ['exception_class' => $exception::class]);
                $counts['result'] = $this->recordFailure($project, $revision, $cursor, 'failed');

                return $counts;
            }

            if ($committed === null) {
                $counts['result'] = 'changed';

                return $counts;
            }
            $counts['result'] = 'ok';
            $counts['processed'] += $committed['processed'];
            $counts['granted'] += $committed['granted'];
            $counts['pages']++;
            if (! $page->hasMore) {
                break;
            }
        }

        return $counts;
    }

    private function moduleEnabled(bool $lock = false): bool
    {
        $query = ModuleState::query()->whereKey('voting');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()?->enabled === true;
    }

    private function current(string $project, int $revision, string $cursor): ?VotingSetting
    {
        if (! $this->moduleEnabled(true)) {
            return null;
        }
        $setting = VotingSetting::query()->where('provider_code', 'ktreon')->lockForUpdate()->first();
        if ($setting === null || $setting->project_id !== $project || $setting->credential_revision !== $revision
            || $setting->event_cursor !== $cursor || $setting->sync_retry_at?->isFuture()) {
            return null;
        }

        return $setting;
    }

    private function recordFailure(string $project, int $revision, string $cursor, string $result, int $retryAfter = 0): string
    {
        try {
            return DB::transaction(function () use ($project, $revision, $cursor, $result, $retryAfter): string {
                $current = $this->current($project, $revision, $cursor);
                if ($current === null) {
                    return 'changed';
                }
                $result = in_array($result, ['rate_limited', 'unauthorized', 'unavailable', 'invalid_response', 'not_configured', 'failed'], true)
                    ? $result : 'failed';
                $current->forceFill([
                    'last_sync_result' => $result,
                    'sync_retry_at' => now()->addSeconds($result === 'rate_limited' ? max(60, min(3600, $retryAfter)) : 300),
                ]);
                if (in_array($result, ['unauthorized', 'not_configured'], true)) {
                    $current->forceFill([
                        'player_access_enabled' => false,
                        'last_check_result' => $result,
                        'last_checked_at' => now(),
                    ]);
                }
                $current->save();

                return $result;
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('Voting synchronization result could not be saved.', ['exception_class' => $exception::class]);

            return 'failed';
        }
    }
}
