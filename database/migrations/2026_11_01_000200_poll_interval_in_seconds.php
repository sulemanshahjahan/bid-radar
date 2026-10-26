<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The poll interval is now set in seconds (poll_interval_seconds, default 30, the owner's
 * choice on 2026-10-27). The old minutes setting is removed so the new default applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::query()->where('key', 'poll_interval_minutes')->delete();
    }
};
