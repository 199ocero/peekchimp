<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('analytics:prune')->dailyAt('02:30');
Schedule::command('analytics:geoip:update')
    ->weeklyOn(1, '03:15')
    ->withoutOverlapping(30)
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
