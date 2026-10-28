<?php

use Illuminate\Support\Facades\Schedule;

// Freelancer poller. Runs every 30 seconds but only polls when the interval in
// Settings is due, polling is allowed (.env + Settings) and not paused.
Schedule::command('bidradar:poll')->everyThirtySeconds()->withoutOverlapping(5);

// Telegram alert if polling is on but no poll has succeeded for 30 minutes.
Schedule::command('bidradar:watchdog')->everyMinute();

// Freelancer API Terms: no project data older than 24 hours. Hourly keeps it strict.
Schedule::command('bidradar:purge')->hourly();

// Daily counts for the previous day (counts only), before the purge removes that day's rows.
Schedule::command('bidradar:daily-stats')->dailyAt('00:05');

// Daily SQLite backup (kept 14 days in storage/app/backups).
Schedule::command('bidradar:backup')->dailyAt('03:30');
