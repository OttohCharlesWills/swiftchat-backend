<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('updates:cleanup')
    ->everyMinute();

// Sends the messages people scheduled, once their time has come.
Schedule::command('messages:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');