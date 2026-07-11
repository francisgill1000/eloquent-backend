<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::command('invoices:update-overdue-status')->daily();

// Hunt — mark abandoned credit-pack checkouts (pending >24h) as failed. Cosmetic
// only. Also runs lazily on the credits endpoint since prod has no cron yet.
Schedule::command('hunt:expire-pending-purchases')->daily();

// Lead Finder — surface leads whose follow-up is due today.
Schedule::command('leads:due-followups')->daily();

// Bookings — customer WhatsApp reminders for appointments entering the 24h window.
Schedule::command('bookings:send-reminders')->hourly()->withoutOverlapping();

// Bookings — post-visit WhatsApp review requests for completed bookings.
Schedule::command('reviews:send-requests')->hourly()->withoutOverlapping();

Schedule::command('assistant:suggest-kb --days=7')
    ->weeklyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

// AI summaries — pre-generate each active shop's performance summary overnight
// (03:00 Asia/Dubai) for the 30 days ending yesterday, so it loads instantly
// from the DB in the morning instead of triggering a live Claude call.
Schedule::command('ai:daily-summaries')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

// AI summaries — pre-generate active shops' weekly (Mon 03:30) and monthly
// (1st 04:00) summaries so those history views load instantly.
Schedule::command('ai:period-summaries --period=week')
    ->weeklyOn(1, '03:30')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ai:period-summaries --period=month')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping()
    ->onOneServer();

