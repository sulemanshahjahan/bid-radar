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

    public function test_blocked_and_low_projects_record_their_codes(): void
    {
        $unverified = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 40]));
        ScoreProject::dispatchSync($unverified->id);

        $d = $unverified->fresh()->decision;
        $this->assertSame('blocked', $d['decision']);
        $this->assertEqualsCanonicalizing(['payment', 'below_medium'], $d['blockers']);
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

    // --- Daily stats -------------------------------------------------------------------

    private function dayProject(array $overrides, array $details = []): Project
    {
        $p = $this->makeProject(array_merge(['source' => 'freelancer', 'title' => 'Secret client title', 'description' => 'Secret brief text'], $overrides), $details);
        $p->forceFill(['created_at' => Carbon::parse('2026-10-27 10:00:00')])->save();

        return $p;
    }

    public function test_daily_stats_count_filters_bands_skills_alerts_and_crowding(): void
    {
        $this->dayProject(['ai_state' => 'filtered', 'filter_reason' => 'Too many bids (180)'], ['skills' => ['SEO']]);
        $this->dayProject(['ai_state' => 'filtered', 'filter_reason' => 'Excluded keyword: backlinks'], ['skills' => ['SEO']]);
        $high = $this->dayProject(['fit_score' => 80, 'ai_state' => 'scored', 'posted_at' => Carbon::parse('2026-10-27 09:59:00'),
            'alerted_at' => Carbon::parse('2026-10-27 10:00:20'), 'decision' => ['decision' => 'alert_and_draft', 'alert' => ['silent' => false]]],
            ['skills' => ['WordPress', 'PHP'], 'bid_count' => 150]);
        BidSnapshot::query()->create(['project_id' => $high->id, 'bid_count' => 2, 'recorded_at' => Carbon::parse('2026-10-27 09:59:30')]);
        BidSnapshot::query()->create(['project_id' => $high->id, 'bid_count' => 64, 'recorded_at' => Carbon::parse('2026-10-27 10:00:30')]);
        $this->dayProject(['fit_score' => 20, 'ai_state' => 'scored'], ['skills' => ['PHP'], 'bid_count' => 12]);
        $this->makeProject(['source' => 'freelancer', 'ai_state' => 'filtered', 'filter_reason' => 'No budget']); // today: not counted
        ApiCall::query()->create(['provider' => 'freelancer', 'purpose' => 'poll', 'status' => 'ok', 'meta' => ['page_full' => true]])
            ->forceFill(['created_at' => Carbon::parse('2026-10-27 08:00:00')])->save();

        $this->artisan('bidradar:daily-stats')->assertSuccessful(); // default: yesterday (2026-10-27)

        $v = fn ($metric, $key) => DailyStat::query()->where('metric', $metric)->where('key', $key)->value('value');
        $this->assertSame(4, $v('discovered', 'total'));
        $this->assertSame(1, $v('filter', 'Too many bids'));
        $this->assertSame(1, $v('filter', 'Excluded keyword: backlinks'));
        $this->assertSame(2, $v('filter', 'passed'));
        $this->assertSame(2, $v('skill', 'PHP'));
        $this->assertSame(1, $v('skill_medium_plus', 'WordPress'));
        $this->assertSame(1, $v('band', 'high'));
        $this->assertSame(1, $v('band', 'low'));
        $this->assertSame(1, $v('alert', 'loud'));
        $this->assertSame(1, $v('decision', 'alert_and_draft'));
        $this->assertSame(1, $v('latest_bids', 'alerted:100+'));
        $this->assertSame(1, $v('bids_2min_vs_latest', '30+|latest100+'));
        $this->assertSame(1, $v('polls', 'page_full'));
        $this->assertNull($v('filter', 'No budget'));
    }

    public function test_daily_stats_hold_no_project_text_and_rerunning_replaces(): void
    {
        $this->dayProject(['fit_score' => 80, 'ai_state' => 'scored']);

        $this->artisan('bidradar:daily-stats', ['--date' => '2026-10-27'])->assertSuccessful();
        $first = DailyStat::query()->count();
        $this->artisan('bidradar:daily-stats', ['--date' => '2026-10-27'])->assertSuccessful();

        $this->assertSame($first, DailyStat::query()->count(), 'Re-running the same day replaces its counts');
        $dump = json_encode(DailyStat::query()->get(['metric', 'key', 'value'])->toArray());
        $this->assertStringNotContainsString('Secret', $dump);
        $this->assertSame('Budget too low', DailyStats::reason('Budget too low ($12 USD)'));
    }

    public function test_daily_stats_are_scheduled_after_midnight_for_the_previous_day(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'bidradar:daily-stats'));

        $this->assertNotNull($event);
        $this->assertSame('5 0 * * *', $event->expression);
    }
}
