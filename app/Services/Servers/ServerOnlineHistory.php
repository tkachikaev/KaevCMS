<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * History of online players for the dashboard chart. The server monitor records the
 * counted online once per five-minute bucket; buckets are shared by all servers, so the
 * total online at a moment is the sum of one bucket.
 */
final class ServerOnlineHistory
{
    public const BUCKET_SECONDS = 300;

    public const RETENTION_DAYS = 35;

    public function record(GameServer $server, int $players, ?CarbonImmutable $at = null): void
    {
        $bucket = $this->bucket($at ?? CarbonImmutable::now());

        try {
            DB::table('server_online_samples')->upsert(
                [['game_server_id' => $server->id, 'bucket_at' => $bucket, 'players' => max(0, $players)]],
                ['game_server_id', 'bucket_at'],
                ['players'],
            );

            // History must never grow without bound; prune at most once an hour.
            if (Cache::add('server-online-history:pruned', true, now()->addHour())) {
                $this->prune();
            }
        } catch (Throwable $exception) {
            // The monitor's main job is the current status; a lost sample is acceptable.
            Log::warning('Server online sample was not recorded.', [
                'game_server_id' => $server->id,
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function prune(): int
    {
        return DB::table('server_online_samples')
            ->where('bucket_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }

    /**
     * Online for the dashboard chart: the total of all game servers and each server on its
     * own, as the highest value per day (last $days days), per hour (last $hours hours) and
     * the value of each of the last $buckets five-minute steps. Points without samples are
     * null. 'peak' is the highest total of the whole day range.
     *
     * @return array{servers:list<array{id:int,name:string}>,total:array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>},per_server:array<int,array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>}>,peak:int|null}
     */
    public function chart(int $days = 30, int $hours = 24, int $buckets = 60): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $dayStart = $today->subDays($days - 1);
        $currentHour = CarbonImmutable::now()->startOfHour();
        $hourStart = $currentHour->subHours($hours - 1);
        $recent = self::recentBuckets($buckets);

        $servers = GameServer::query()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (GameServer $server): array => ['id' => (int) $server->id, 'name' => $server->nameFor()])
            ->values()->all();
        $known = array_fill_keys(array_column($servers, 'id'), true);

        // One pass over the stored rows, keyed by the "Y-m-d H:i" text of each step: 30 days
        // of several servers are tens of thousands of rows, too many for date objects.
        $totals = [];
        $perServer = [];
        DB::table('server_online_samples')
            ->where('bucket_at', '>=', min($dayStart, $hourStart, $recent[0]))
            ->get(['game_server_id', 'bucket_at', 'players'])
            ->each(static function (object $row) use (&$totals, &$perServer, $known): void {
                $serverId = (int) $row->game_server_id;
                if (! isset($known[$serverId])) {
                    return;
                }
                $key = self::bucketKey((string) $row->bucket_at);
                $players = (int) $row->players;
                $totals[$key] = ($totals[$key] ?? 0) + $players;
                $perServer[$serverId][$key] = $players;
            });

        $dayKeys = [];
        for ($day = $dayStart; $day->lte($today); $day = $day->addDay()) {
            $dayKeys[] = $day->toDateString();
        }
        $hourKeys = [];
        for ($hour = $hourStart; $hour->lte($currentHour); $hour = $hour->addHour()) {
            $hourKeys[] = $hour->format('Y-m-d H');
        }
        $minuteKeys = array_map(static fn (CarbonImmutable $bucket): string => $bucket->format('Y-m-d H:i'), $recent);
        $hourFloor = $hourStart->format('Y-m-d H:i');

        /** @param array<string,int> $steps */
        $series = static function (array $steps) use ($dayKeys, $hourKeys, $minuteKeys, $hourFloor): array {
            $dayPeaks = [];
            $hourPeaks = [];
            foreach ($steps as $key => $players) {
                $day = substr($key, 0, 10);
                $dayPeaks[$day] = max($dayPeaks[$day] ?? 0, $players);
                if ($key >= $hourFloor) {
                    $hour = substr($key, 0, 13);
                    $hourPeaks[$hour] = max($hourPeaks[$hour] ?? 0, $players);
                }
            }

            return [
                'days' => array_map(static fn (string $key): ?int => $dayPeaks[$key] ?? null, $dayKeys),
                'hours' => array_map(static fn (string $key): ?int => $hourPeaks[$key] ?? null, $hourKeys),
                'minutes' => array_map(static fn (string $key): ?int => $steps[$key] ?? null, $minuteKeys),
            ];
        };

        $total = $series($totals);
        $present = array_filter($total['days'], static fn (?int $value): bool => $value !== null);

        return [
            'servers' => $servers,
            'total' => $total,
            'per_server' => array_map($series, array_replace(array_fill_keys(array_keys($known), []), $perServer)),
            'peak' => $present === [] ? null : max($present),
        ];
    }

    /**
     * Starts of the last $count five-minute buckets, oldest first; the last one is current.
     *
     * @return list<CarbonImmutable>
     */
    public static function recentBuckets(int $count): array
    {
        $now = CarbonImmutable::now();
        $current = $now->setTime((int) $now->format('H'), intdiv((int) $now->format('i'), 5) * 5);

        return array_map(static fn (int $index): CarbonImmutable => $current->subMinutes(($count - 1 - $index) * 5), range(0, $count - 1));
    }

    /** "Y-m-d H:i" of the five-minute bucket a stored "Y-m-d H:i:s" time falls in. */
    public static function bucketKey(string $time): string
    {
        return substr($time, 0, 14).sprintf('%02d', intdiv((int) substr($time, 14, 2), 5) * 5);
    }

    private function bucket(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = $at->getTimestamp();

        return CarbonImmutable::createFromTimestamp($timestamp - ($timestamp % self::BUCKET_SECONDS), $at->getTimezone());
    }
}
