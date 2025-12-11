<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Security maintenance tasks
Schedule::command('security:cleanup-counters')->hourly();
Schedule::command('security:decay-risk-scores')->daily();
