<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('kaevcms:about', function () {
    $this->info('KaevCMS — open-source CMS for Lineage II servers.');
})->purpose('Show KaevCMS information');

Schedule::command('kaevcms:scheduler-heartbeat')
    ->everyMinute();

Schedule::command('kaevcms:servers-monitor')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('kaevcms:logs-clean')
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command('kaevcms:queue-drain')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('kaevcms:queue-clean')
    ->dailyAt('03:45')
    ->withoutOverlapping();

Schedule::command('kaevcms:rewards-reconcile --limit=50 --older-than=300')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

Schedule::command('kaevcms:cache-clean --batch=2000')
    ->dailyAt('03:55')
    ->withoutOverlapping();

Schedule::command('kaevcms:news-media-clean --hours=24')
    ->dailyAt('04:15')
    ->withoutOverlapping();

Schedule::command('kaevcms:page-media-clean --hours=24')
    ->dailyAt('04:20')
    ->withoutOverlapping();
