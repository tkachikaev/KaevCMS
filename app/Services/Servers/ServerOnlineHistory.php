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
     * Highest total online per day for the last $days days (null for days without samples).
     *
     * @return array{days:list<array{date:CarbonImmutable,peak:int|null,average:int|null}>,peak:int|null,peak_at:CarbonImmutable|null,average_peak:float|null}
     */
    public function dailyPeaks(int $days = 30): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $start = $today->subDays($days - 1);

        $totals = [];
        DB::table('server_online_samples')
            ->where('bucket_at', '>=', $start)
            ->groupBy('bucket_at')
            ->orderBy('bucket_at')
            ->get(['bucket_at', DB::raw('SUM(players) as players')])
            ->each(static function (object $row) use (&$totals): void {
                $totals[] = ['at' => CarbonImmutable::parse((string) $row->bucket_at), 'players' => (int) $row->players];
            });

        $peaks = [];
        $sums = [];
        $counts = [];
        $peak = null;
        $peakAt = null;
        foreach ($totals as $total) {
            $key = $total['at']->toDateString();
            $peaks[$key] = max($peaks[$key] ?? 0, $total['players']);
            $sums[$key] = ($sums[$key] ?? 0) + $total['players'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($peak === null || $total['players'] > $peak) {
                $peak = $total['players'];
                $peakAt = $total['at'];
            }
        }

        $series = [];
        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $day,
                'peak' => $peaks[$key] ?? null,
                'average' => isset($sums[$key]) ? (int) round($sums[$key] / $counts[$key]) : null,
            ];
        }

        return [
            'days' => $series,
            'peak' => $peak,
            'peak_at' => $peakAt,
            'average_peak' => $peaks === [] ? null : array_sum($peaks) / count($peaks),
        ];
    }

    /**
     * Highest and average total online per hour for the last $hours hours, oldest first
     * (null for hours without samples).
     *
     * @return list<array{peak:int|null,average:int|null}>
     */
    public function hourly(int $hours = 24): array
    {
        $currentHour = CarbonImmutable::now()->startOfHour();
        $start = $currentHour->subHours($hours - 1);

        $peaks = [];
        $sums = [];
        $counts = [];
        DB::table('server_online_samples')
            ->where('bucket_at', '>=', $start)
            ->groupBy('bucket_at')
            ->get(['bucket_at', DB::raw('SUM(players) as players')])
            ->each(static function (object $row) use (&$peaks, &$sums, &$counts): void {
                $key = CarbonImmutable::parse((string) $row->bucket_at)->startOfHour()->format('Y-m-d H');
                $players = (int) $row->players;
                $peaks[$key] = max($peaks[$key] ?? 0, $players);
                $sums[$key] = ($sums[$key] ?? 0) + $players;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });

        $series = [];
        for ($hour = $start; $hour->lte($currentHour); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d H');
            $series[] = [
                'peak' => $peaks[$key] ?? null,
                'average' => isset($sums[$key]) ? (int) round($sums[$key] / $counts[$key]) : null,
            ];
        }

        return $series;
    }

    private function bucket(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = $at->getTimestamp();

        return CarbonImmutable::createFromTimestamp($timestamp - ($timestamp % self::BUCKET_SECONDS), $at->getTimezone());
    }
}
