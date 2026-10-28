<?php

use Illuminate\Support\Facades\Schedule;

// Freelancer poller. Runs every 30 seconds but only polls when the interval in
// Settings is due, polling is allowed (.env + Settings) and not paused.
Schedule::command('bidradar:poll')->everyThirtySeconds()->withoutOverlapping(5);

// Telegram alert if polling is on but no poll has succeeded for 30 minutes.
Schedule::command('bidradar:watchdog')->everyMinute();

// Freelancer API Terms: no project data older than 24 hours. Hourly keeps it strict.
Schedule::command('bidradar:purge')->hourly();

// Hourly counts (counts only), each hour counted ~20-24h after it starts, before the purge reaches it.
// At :30 so it never runs alongside the purge at :00.
Schedule::command('bidradar:daily-stats')->hourlyAt(30)->withoutOverlapping(30);

// Daily SQLite backup (kept 14 days in storage/app/backups).
Schedule::command('bidradar:backup')->dailyAt('03:30');
