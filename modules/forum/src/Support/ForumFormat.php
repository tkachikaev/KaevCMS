<?php

namespace KaevCMS\Modules\Forum\Support;

use Illuminate\Support\Carbon;

/** Small display helpers shared by the forum lists. */
final class ForumFormat
{
    /** Compact counters like 12,3K for big numbers; plain numbers otherwise. */
    public static function count(int $value): string
    {
        return $value >= 10000
            ? format_number($value / 1000, $value >= 100000 ? 0 : 1).'K'
            : format_number($value);
    }

    /** "Today at 05:56", "Yesterday at 18:33", otherwise the date and time. */
    public static function when(mixed $date): string
    {
        $date = Carbon::parse($date);

        return match (true) {
            $date->isToday() => __('module-forum::messages.today_at', ['time' => $date->format('H:i')]),
            $date->isYesterday() => __('module-forum::messages.yesterday_at', ['time' => $date->format('H:i')]),
            default => $date->format('d.m.Y H:i'),
        };
    }
}
