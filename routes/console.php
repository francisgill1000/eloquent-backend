<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('invoices:update-overdue-status')->daily();

Schedule::command('assistant:suggest-kb --days=7')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

