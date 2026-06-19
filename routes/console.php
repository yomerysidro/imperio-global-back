<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

$schedule = app(Schedule::class);


$schedule->command('app:reset-users-active')
    ->timezone('America/Lima')
    ->monthlyOn(3, '01:00');

$schedule->command('app:range-list-bulk')
    ->timezone('America/Lima')
    ->hourly();

// $schedule->command('app:range-list-bulk')
//     ->timezone('America/Lima')
//     ->at('12:00');

// $schedule->command('app:range-list-bulk')
//     ->timezone('America/Lima')
//     ->at('18:00');

// $schedule->command('app:range-list-bulk')
//     ->timezone('America/Lima')
//     ->at('23:00');

$schedule->command('app:user-temp-send-email')
    ->timezone('America/Lima')
    ->everyThirtyMinutes();
