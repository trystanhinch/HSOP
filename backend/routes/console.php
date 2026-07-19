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
