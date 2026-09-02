<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('milestones:auto-approve')->daily();

// Expire featured job and profile listings past their expires_at
Schedule::command('featured:listings:expire')->daily();

// Reconcile stuck pending deposits (safety net for missed webhooks/polls)
Schedule::command('payments:reconcile-pending --minutes=5')->everyFiveMinutes();
