<?php

use Illuminate\Support\Facades\Schedule;

// Freelancer poller. Runs every minute but only polls when the interval in
// Settings is due, polling is allowed (.env + Settings) and not paused.
Schedule::command('bidradar:poll')->everyMinute()->withoutOverlapping(5);

// Freelancer API Terms: no project data older than 24 hours. Hourly keeps it strict.
Schedule::command('bidradar:purge')->hourly();

// Daily SQLite backup (kept 14 days in storage/app/backups).
Schedule::command('bidradar:backup')->dailyAt('03:30');
