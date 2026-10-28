<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Measurements for later analysis. None of these hold Freelancer project text:
 * - projects.bid_count_observed(_at): the last bid count BidRadar saw before I marked "Bid placed",
 *   and when it saw it (not the exact count at submission). Kept like bid_count_at_bid.
 * - projects.decision: BidRadar's own score, adjustments and alert decision. Not wiped by the purge.
 * - api_calls.meta: per-poll counts (returned, new, known, page full).
 * - daily_stats: counts only, per UTC hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('bid_count_observed')->nullable()->after('bid_count_at_bid');
            $table->timestamp('bid_count_observed_at')->nullable()->after('bid_count_observed');
            $table->json('decision')->nullable()->after('scoring');
        });

        Schema::table('api_calls', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('error');
        });

        // Counted per UTC hour (bidradar:daily-stats); a day is the sum of its hours.
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->string('metric');
            $table->string('key');
            $table->unsignedInteger('value');
            $table->timestamps();

            $table->unique(['date', 'hour', 'metric', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');

        Schema::table('api_calls', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['bid_count_observed', 'bid_count_observed_at', 'decision']);
        });
    }
};
