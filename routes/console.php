<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run once a day — the command itself only sends at most one reminder per
// calendar day per installment (see SendTuitionReminders), so running it
// more often wouldn't send anything extra, just waste a query.
Schedule::command('tuition:send-reminders')->dailyAt('08:00');

// Safety net for online payments: confirms any unfinished PayMongo checkout
// directly with PayMongo, in case the parent closed the tab before being
// sent back and the webhook never arrived (e.g. running on localhost).
Schedule::command('paymongo:reconcile')->everyFiveMinutes()->withoutOverlapping();
