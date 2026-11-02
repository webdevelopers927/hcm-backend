<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Uploads that were never attached to an employee.
Schedule::command('uploads:prune')->hourly();

// Shift changes scheduled for today (or missed while the scheduler was down).
Schedule::command('shifts:apply-scheduled')->dailyAt('00:05');

// Offboarded employees leave the day after their last working day.
Schedule::command('offboarding:process-exits')->dailyAt('00:10');
