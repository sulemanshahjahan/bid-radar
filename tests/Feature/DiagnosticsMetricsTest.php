<?php

namespace Tests\Feature;

use App\Console\Commands\DailyStats;
use App\Jobs\ScoreProject;
use App\Models\ApiCall;
use App\Models\BidSnapshot;
use App\Models\DailyStat;
use App\Models\Project;
use App\Services\Settings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiagnosticsMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    // --- Decision metadata ----------------------------------------------------------

    public function test_scoring_records_the_alert_decision_with_scores_and_rule_labels(): void
    {
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 3]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90, 'estimated_hours' => 3]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $d = $project->fresh()->decision;
        $this->assertSame('alert_and_draft', $d['decision']);
        $this->assertSame(90, $d['base_score']);
        $this->assertSame(90, $d['score']);
        $this->assertSame('fix', $d['category']);
        $this->assertSame(3, $d['estimated_hours']);
        $this->assertSame(75, $d['threshold_high']);
        $this->assertSame([], $d['blockers']);
        $this->assertFalse($d['alert']['silent'], 'The alert step adds how it was sent');
    }

    public function test_decision_holds_no_raw_bid_counts_or_budgets(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'posted_at' => now()->subHour()], ['bid_count' => 123]);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));

        ScoreProject::dispatchSync($project->id);

        $d = $project->fresh()->decision;
        $this->assertSame([['label' => 'bids (31+)', 'points' => -25]], $d['adjustments']);
        $this->assertStringNotContainsString('123', json_encode($d));
        $this->assertSame('budget top under minimum', ScoreProject::ruleLabel('budget top under minimum ($12)'));
    }

    public function test_client_payment_status_is_never_retained_in_the_decision(): void
    {
        // Low score + unverified payment: held, only the score blocker is kept.
        $low = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 40]));
        ScoreProject::dispatchSync($low->id);

        $d = $low->fresh()->decision;
        $this->assertSame('held', $d['decision']);
        $this->assertSame(['below_medium'], $d['blockers']);

        // High fit held back only by payment: alerted, but nothing says why it was not drafted.
        $high = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90]));
        ScoreProject::dispatchSync($high->id);

        $d = $high->fresh()->decision;
        $this->assertSame('held', $d['decision']);
        $this->assertSame([], $d['blockers']);
        $this->assertSame(['sent_at', 'silent', 'quiet'], array_keys($d['alert']));
        foreach ([$low, $high] as $p) {
            $this->assertStringNotContainsStringIgnoringCase('payment', json_encode($p->fresh()->decision));
            $this->assertStringNotContainsStringIgnoringCase('verified', json_encode($p->fresh()->decision));
        }
    }

    public function test_rank_rebuild_client_adjustments_are_not_itemized_in_the_decision(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true]);
        $project = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false, 'rating' => 4.9, 'projects' => 5]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 70, 'quick_fix' => true, 'large_vague_or_off_niche' => false]));

        ScoreProject::dispatchSync($project->id);

        $project->refresh();
        $labels = array_column($project->scoring['adjustments'], 'label');
        $this->assertContains('payment not verified', $labels, 'Scoring itself is unchanged (purged after 24h)');
        $this->assertContains('client 4.8+ with 3+ projects', $labels);

        $d = $project->decision;
        $this->assertSame([['label' => 'quick fix (1-2 days)', 'points' => 10]], $d['adjustments']);
        $this->assertSame($project->fit_score, $d['score'], 'Score still includes every adjustment');
        $this->assertStringNotContainsStringIgnoringCase('payment', json_encode($d));
        $this->assertStringNotContainsString('client 4.8', json_encode($d));
    }

    public function test_a_failing_decision_write_never_blocks_the_alert_or_the_draft(): void
    {
        Project::saving(function (Project $p) {
            if ($p->isDirty('decision')) {
                throw new \RuntimeException('database is locked');
            }
        });
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 3]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $project->refresh();
        $this->assertNotNull($project->alerted_at, 'Alert sent');
        $this->assertSame(1, $project->drafts()->count(), 'Draft written');
        $this->assertNull($project->decision);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
    }

    public function test_quiet_alert_decision_is_recorded(): void
    {
        app(Settings::class)->set(['quiet_alerts' => true]);
        $project = $this->makeProject(['source' => 'freelancer']);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));

        ScoreProject::dispatchSync($project->id);

        $d = $project->fresh()->decision;
        $this->assertSame('quiet_alert', $d['decision']);
        $this->assertTrue($d['alert']['quiet']);
    }

    public function test_the_purge_wipes_project_data_but_keeps_the_decision(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'status' => 'drafted', 'decision' => ['decision' => 'alert_and_draft', 'score' => 80]]);
        $project->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->artisan('bidradar:purge')->assertSuccessful();

        $project->refresh();
        $this->assertNotNull($project->purged_at);
        $this->assertNull($project->details);
        $this->assertSame('alert_and_draft', $project->decision['decision']);
    }

    // --- Hourly counts (bidradar:daily-stats) -----------------------------------------

    /** A live project created in the hour 2026-10-27 14:00 UTC (counted from 10-28 10:00, safe until 13:55). */
    private function hourProject(array $overrides, array $details = [], string $at = '2026-10-27 14:10:00'): Project
    {
        $p = $this->makeProject(array_merge(['source' => 'freelancer', 'title' => 'Secret client title', 'description' => 'Secret brief text'], $overrides), $details);
        $p->forceFill(['created_at' => Carbon::parse($at)])->save();

        return $p;
    }

    /** @return int|null value summed over the hours of 2026-10-27 */
    private function day(string $metric, string $key): ?int
    {
        $rows = DailyStat::query()->whereDate('date', '2026-10-27')->where('metric', $metric)->where('key', $key);

        return $rows->exists() ? (int) $rows->sum('value') : null;
    }

    public function test_an_hour_is_counted_with_filters_bands_skills_alerts_and_crowding(): void
    {
        $this->hourProject(['ai_state' => 'filtered', 'filter_reason' => 'Too many bids (180)'], ['skills' => ['SEO']]);
        $this->hourProject(['ai_state' => 'filtered', 'filter_reason' => 'Excluded keyword: backlinks'], ['skills' => ['SEO']]);
        $high = $this->hourProject(['fit_score' => 80, 'ai_state' => 'scored', 'posted_at' => Carbon::parse('2026-10-27 14:09:00'),
            'alerted_at' => Carbon::parse('2026-10-27 14:10:20'), 'decision' => ['decision' => 'alert_and_draft', 'alert' => ['silent' => false]]],
            ['skills' => ['WordPress', 'PHP'], 'bid_count' => 150]);
        BidSnapshot::query()->create(['project_id' => $high->id, 'bid_count' => 2, 'recorded_at' => Carbon::parse('2026-10-27 14:09:30')]);
        BidSnapshot::query()->create(['project_id' => $high->id, 'bid_count' => 64, 'recorded_at' => Carbon::parse('2026-10-27 14:10:30')]);
        $this->hourProject(['fit_score' => 20, 'ai_state' => 'scored'], ['skills' => ['PHP'], 'bid_count' => 12]);
        $this->hourProject(['ai_state' => 'filtered', 'filter_reason' => 'No budget'], [], '2026-10-27 17:10:00'); // not due until 10-28 13:00
        ApiCall::query()->create(['provider' => 'freelancer', 'purpose' => 'poll', 'status' => 'ok', 'meta' => ['page_full' => true]])
            ->forceFill(['created_at' => Carbon::parse('2026-10-27 14:20:00')])->save();

        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $this->assertSame(4, $this->day('discovered', 'total'));
        $this->assertSame(1, $this->day('filter', 'Too many bids'));
        $this->assertSame(1, $this->day('filter', 'Excluded keyword: backlinks'));
        $this->assertSame(2, $this->day('filter', 'passed'));
        $this->assertSame(2, $this->day('skill', 'PHP'));
        $this->assertSame(1, $this->day('skill_medium_plus', 'WordPress'));
        $this->assertSame(1, $this->day('band', 'high'));
        $this->assertSame(1, $this->day('band', 'low'));
        $this->assertSame(1, $this->day('alert', 'loud'));
        $this->assertSame(1, $this->day('decision', 'alert_and_draft'));
        $this->assertSame(1, $this->day('latest_bids', 'alerted:100+'));
        $this->assertSame(1, $this->day('bids_2min_vs_latest', '30+|latest100+'));
        $this->assertSame(1, $this->day('polls', 'page_full'));
        $this->assertNull($this->day('filter', 'No budget'), 'Its hour is not due yet');
    }

    public function test_reruns_never_double_count_and_a_recount_replaces_the_hour(): void
    {
        $this->hourProject(['fit_score' => 80, 'ai_state' => 'scored']);

        $this->artisan('bidradar:daily-stats')->assertSuccessful();
        $rows = DailyStat::query()->count();
        $this->artisan('bidradar:daily-stats')->expectsOutputToContain('Counted 0 hour(s)')->assertSuccessful();
        $this->artisan('bidradar:daily-stats', ['--hour' => '2026-10-27 14'])->assertSuccessful();

        $this->assertSame($rows, DailyStat::query()->count());
        $this->assertSame(1, $this->day('discovered', 'total'));
    }

    public function test_a_delayed_run_still_counts_an_hour_before_the_purge_reaches_it(): void
    {
        $this->hourProject(['fit_score' => 20, 'ai_state' => 'scored']); // low score: the purge deletes it once 24h old
        Carbon::setTestNow(Carbon::parse('2026-10-28 13:50:00')); // runs at 10:30-12:30 all missed

        $this->artisan('bidradar:purge')->assertSuccessful();
        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $this->assertSame(1, $this->day('discovered', 'total'));
    }

    public function test_an_hour_the_purge_has_reached_is_flagged_missed_not_counted_partially(): void
    {
        DailyStat::query()->create(['date' => '2026-10-27', 'hour' => 9, 'metric' => 'meta', 'key' => 'counted_at', 'value' => 1]); // job already running
        $this->hourProject(['fit_score' => 20, 'ai_state' => 'scored']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 14:30:00')); // every run for 14:00 was missed

        $this->artisan('bidradar:purge')->assertSuccessful();
        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $this->assertNull($this->day('discovered', 'total'));
        $this->assertSame(1, (int) DailyStat::query()->whereDate('date', '2026-10-27')->where('hour', 14)->where('key', 'missed')->value('value'));
        $this->artisan('bidradar:daily-stats', ['--hour' => '2026-10-27 14'])->assertFailed();
    }

    public function test_hours_before_the_first_run_are_not_flagged_missed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 14:30:00'));

        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $this->assertFalse(DailyStat::query()->where('key', 'missed')->exists());
    }

    public function test_hourly_counts_hold_no_project_text(): void
    {
        $this->hourProject(['fit_score' => 80, 'ai_state' => 'scored']);

        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $dump = json_encode(DailyStat::query()->get(['metric', 'key', 'value'])->toArray());
        $this->assertStringNotContainsString('Secret', $dump);
        $this->assertSame('Budget too low', DailyStats::reason('Budget too low ($12 USD)'));
    }

    public function test_hourly_counts_are_scheduled_at_half_past_away_from_the_purge(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'bidradar:daily-stats'));

        $this->assertNotNull($event);
        $this->assertSame('30 * * * *', $event->expression);
    }
}
