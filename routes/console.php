<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('kaevcms:about', function () {
    $this->info('KaevCMS — open-source CMS for Lineage II servers.');
})->purpose('Show KaevCMS information');

Artisan::command('l2forge:about', function () {
    $this->warn('The l2forge:about alias is deprecated. Use kaevcms:about.');
    $this->info('KaevCMS — open-source CMS for Lineage II servers.');
})->purpose('Legacy alias for kaevcms:about');

Artisan::command('cms:about', function () {
    $this->warn('The cms:about alias is deprecated. Use kaevcms:about.');
    $this->info('KaevCMS — open-source CMS for Lineage II servers.');
})->purpose('Legacy alias for kaevcms:about');

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
