<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('funds:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('pix:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('platform-reviews:dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
