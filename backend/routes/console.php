<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep store products and orders current (needs the scheduler cron and a queue worker on the server)
Schedule::command('stores:sync')->everyFifteenMinutes()->withoutOverlapping();
