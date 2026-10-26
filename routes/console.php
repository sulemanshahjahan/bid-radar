<?php

use Illuminate\Support\Facades\Schedule;

// Daily SQLite backup (kept 14 days in storage/app/backups).
Schedule::command('bidradar:backup')->dailyAt('03:30');

// Phase 2 adds the Freelancer poller (every 2 minutes, only when polling is
// allowed) and the nightly 24h purge of project data.
