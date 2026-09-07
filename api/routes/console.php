<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduled maintenance.
|
| None of these are load-bearing for correctness — expiry and progress are
| evaluated live on every request. They exist so abandoned state does not sit
| around, and so drift in a stored aggregate is noticed.
*/
Schedule::command('quiz:sweep-expired')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('enrollment:sweep-expired')->hourly()->withoutOverlapping();
Schedule::command('progress:reconcile')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('usage:reconcile')->dailyAt('03:30')->withoutOverlapping();
