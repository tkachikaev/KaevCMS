<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Data for the dashboard chart widgets: one widget per metric (online, registrations,
 * top-ups), each with toggleable lines in the same unit over 30 days or the last 24 hours.
 * The browser takes the last 7 days from the 30-day period. Missing values are null.
 */
final class DashboardTrends
{
    /**
     * @param  array{days:list<array{date:CarbonImmutable,count:int,game_accounts:int}>,hours:list<array{masters:int,games:int}>}|null  $registrations
     * @param  array{days:list<array{date:CarbonImmutable,peak:int|null,average:int|null}>,hours:list<array{peak:int|null,average:int|null}>}|null  $online
     * @param  array{currency:string|null,days:list<array{date:CarbonImmutable,amount:float}>,hours:list<float>}|null  $donations
     * @return list<array<string,mixed>>
     */
    public static function widgets(?array $registrations, ?array $online, ?array $donations): array
    {
        $labels = self::labels();
        $widgets = [];

        if ($online !== null) {
            $widgets[] = self::widget('online', self::text('Players online'), 'number', null, $labels, [
                self::series('peak', self::text('Peak'), 'chart-1', 'max', array_column($online['days'], 'peak'), array_column($online['hours'], 'peak'), true),
                self::series('average', self::text('Average online'), 'chart-4', 'avg', array_column($online['days'], 'average'), array_column($online['hours'], 'average'), true),
            ]);
        }

        if ($registrations !== null) {
            $widgets[] = self::widget('registrations', self::text('Registrations'), 'number', null, $labels, [
                self::series('masters', self::text('Master accounts'), 'chart-2', 'sum', array_column($registrations['days'], 'count'), array_column($registrations['hours'], 'masters'), true),
                self::series('games', self::text('Game accounts'), 'chart-3', 'sum', array_column($registrations['days'], 'game_accounts'), array_column($registrations['hours'], 'games'), true),
            ]);
        }

        if ($donations !== null && $donations['currency'] !== null) {
            $widgets[] = self::widget('donations', self::text('Top-ups'), 'money', $donations['currency'], $labels, [
                self::series('amount', self::text('Successful payments'), 'chart-2', 'sum', array_column($donations['days'], 'amount'), $donations['hours'], true),
            ]);
        }

        return $widgets;
    }

    /**
     * @param  array{days:list<array{short:string,long:string}>,hours:list<array{short:string,long:string}>}  $labels
     * @param  list<array{key:string,label:string,tone:string,summary:string,visible:bool,days:list<int|float|null>,hours:list<int|float|null>}>  $series
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
     * @return array{key:string,label:string,tone:string,summary:string,visible:bool,days:list<int|float|null>,hours:list<int|float|null>}
     */
    private static function series(string $key, string $label, string $tone, string $summary, array $days, array $hours, bool $visible): array
    {
        return ['key' => $key, 'label' => $label, 'tone' => $tone, 'summary' => $summary, 'visible' => $visible, 'days' => $days, 'hours' => $hours];
    }

    /** @return array{days:list<array{short:string,long:string}>,hours:list<array{short:string,long:string}>} */
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

        return ['days' => $days, 'hours' => $hours];
    }

    /** Translation that is always a string (JSON translations never resolve to arrays). */
    private static function text(string $key): string
    {
        $value = __($key);

        return is_string($value) ? $value : $key;
    }
}
