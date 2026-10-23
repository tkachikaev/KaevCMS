<?php

namespace App\Services\Dashboard;

use App\Services\Servers\ServerOnlineHistory;
use Carbon\CarbonImmutable;

/**
 * Data for the dashboard chart widgets: one widget per metric (online, registrations,
 * top-ups), each with toggleable lines in the same unit over 30 days, the last 24 hours
 * and the last 5 hours in five-minute steps. The browser takes the last 7 days and the
 * last hour from those. Missing values are null.
 */
final class DashboardTrends
{
    /**
     * @param  array{days:list<array{date:CarbonImmutable,count:int,game_accounts:int}>,hours:list<array{masters:int,games:int}>,minutes:list<array{masters:int,games:int}>}|null  $registrations
     * @param  array{servers:list<array{id:int,name:string}>,total:array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>},per_server:array<int,array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>}>}|null  $online
     * @param  array{currency:string|null,days:list<array{date:CarbonImmutable,amount:float}>,hours:list<float>,minutes:list<float>}|null  $donations
     * @return list<array<string,mixed>>
     */
    public static function widgets(?array $registrations, ?array $online, ?array $donations): array
    {
        $labels = self::labels();
        $widgets = [];

        if ($online !== null) {
            // The total of all game servers, then each server on its own line; with several
            // servers a picker chooses the lines. Days and hours show their highest online.
            $total = $online['total'];
            $lines = [self::series('total', self::text('Total online'), 'chart-1', 'max', $total['days'], $total['hours'], $total['minutes'], true)];
            if (count($online['servers']) > 1) {
                foreach ($online['servers'] as $server) {
                    $values = $online['per_server'][$server['id']];
                    $lines[] = self::series('server-'.$server['id'], $server['name'], 'chart-2', 'max', $values['days'], $values['hours'], $values['minutes'], true);
                }
            }
            $widget = self::widget('online', self::text('Players online'), 'number', null, $labels, $lines);
            $widget['palette'] = true;
            $widget['picker'] = count($lines) > 1;
            $widget['i18n']['lines'] = self::text('Servers');
            $widgets[] = $widget;
        }

        if ($registrations !== null) {
            $widgets[] = self::widget('registrations', self::text('Registrations'), 'number', null, $labels, [
                self::series('masters', self::text('Master accounts'), 'chart-2', 'sum', array_column($registrations['days'], 'count'), array_column($registrations['hours'], 'masters'), array_column($registrations['minutes'], 'masters'), true),
                self::series('games', self::text('Game accounts'), 'chart-3', 'sum', array_column($registrations['days'], 'game_accounts'), array_column($registrations['hours'], 'games'), array_column($registrations['minutes'], 'games'), true),
            ]);
        }

        // The top-ups chart is always there while donations are on. Without any currency to
        // pay in yet it says there is no data instead of drawing a line.
        if ($donations !== null) {
            $none = static fn (array $values): array => array_fill(0, count($values), null);
            $priced = $donations['currency'] !== null;
            $widgets[] = self::widget('donations', self::text('Top-ups'), $priced ? 'money' : 'number', $donations['currency'], $labels, [
                self::series(
                    'amount',
                    self::text('Successful payments'),
                    'chart-2',
                    'sum',
                    $priced ? array_column($donations['days'], 'amount') : $none($donations['days']),
                    $priced ? $donations['hours'] : $none($donations['hours']),
                    $priced ? $donations['minutes'] : $none($donations['minutes']),
                    true,
                ),
            ]);
        }

        return $widgets;
    }

    /**
     * @param  array{days:list<array{short:string,long:string}>,hours:list<array{short:string,long:string}>,minutes:list<array{short:string,long:string}>}  $labels
     * @param  list<array{key:string,label:string,tone:string,summary:string,visible:bool,days:list<int|float|null>,hours:list<int|float|null>,minutes:list<int|float|null>}>  $series
     * @return array<string,mixed>
     */
    private static function widget(string $key, string $title, string $format, ?string $currency, array $labels, array $series): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'format' => $format,
            'currency' => $currency,
            'labels' => $labels,
            'series' => $series,
            'i18n' => [
                'today' => self::text('Today'),
                'now' => self::text('Now'),
                'no_data' => self::text('No data'),
                'empty' => self::text('No data for this period yet.'),
            ],
        ];
    }

    /**
     * @param  list<int|float|null>  $days
     * @param  list<int|float|null>  $hours
     * @param  list<int|float|null>  $minutes
     * @return array{key:string,label:string,tone:string,summary:string,visible:bool,days:list<int|float|null>,hours:list<int|float|null>,minutes:list<int|float|null>}
     */
    private static function series(string $key, string $label, string $tone, string $summary, array $days, array $hours, array $minutes, bool $visible): array
    {
        return ['key' => $key, 'label' => $label, 'tone' => $tone, 'summary' => $summary, 'visible' => $visible, 'days' => $days, 'hours' => $hours, 'minutes' => $minutes];
    }

    /** @return array{days:list<array{short:string,long:string}>,hours:list<array{short:string,long:string}>,minutes:list<array{short:string,long:string}>} */
    private static function labels(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $days = [];
        for ($day = $today->subDays(29); $day->lte($today); $day = $day->addDay()) {
            $days[] = ['short' => $day->translatedFormat('j M'), 'long' => $day->translatedFormat('j F')];
        }

        $currentHour = CarbonImmutable::now()->startOfHour();
        $hours = [];
        for ($hour = $currentHour->subHours(23); $hour->lte($currentHour); $hour = $hour->addHour()) {
            $hours[] = ['short' => $hour->format('H:00'), 'long' => $hour->translatedFormat('j F, H:00')];
        }

        $minutes = array_map(
            static fn (CarbonImmutable $bucket): array => ['short' => $bucket->format('H:i'), 'long' => $bucket->translatedFormat('j F, H:i')],
            ServerOnlineHistory::recentBuckets(AdminDashboardOverview::RECENT_BUCKETS),
        );

        return ['days' => $days, 'hours' => $hours, 'minutes' => $minutes];
    }

    /** Translation that is always a string (JSON translations never resolve to arrays). */
    private static function text(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
