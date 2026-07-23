<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gmail:fetch-leads')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('gmail.enabled', true));

Schedule::command('workflow:escalation-sweep')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('ops:generate-report daily')
    ->dailyAt('07:00')
    ->withoutOverlapping();

Schedule::command('ops:generate-report weekly')
    ->weeklyOn(1, '07:15')
    ->withoutOverlapping();

Schedule::command('payouts:process-scheduled')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => config('payment.provider') === 'stripe');

Schedule::command('intake:cleanup-sessions')
    ->hourly()
    ->withoutOverlapping();
