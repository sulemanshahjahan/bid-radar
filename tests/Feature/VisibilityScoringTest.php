<?php

namespace Tests\Feature;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Livewire\ProjectShow;
use App\Models\BidSnapshot;
use App\Models\User;
use App\Services\BidTracker;
use App\Services\Freelancer\Poller;
use App\Services\ScoreAdjuster;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class VisibilityScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::createFromTimestamp(1793000700));
    }

    // --- Bid-count adjustment -------------------------------------------------

    public function test_bid_count_adjusts_the_score_in_three_bands(): void
    {
        $adj = app(ScoreAdjuster::class);
        $score = fn (int $bids) => $adj->adjust(['fit_score' => 80], ['bid_count' => $bids])['score'];

        $this->assertSame(80, $score(0));
        $this->assertSame(80, $score(15));
        $this->assertSame(70, $score(16));
        $this->assertSame(70, $score(30));
        $this->assertSame(55, $score(31));
        $this->assertSame(0, $adj->adjust(['fit_score' => 10], ['bid_count' => 200])['score'], 'Never below 0');
        $this->assertSame([['label' => '20 bids (16-30)', 'points' => -10]], $adj->adjust(['fit_score' => 80], ['bid_count' => 20])['adjustments']);
    }

    public function test_thresholds_use_the_adjusted_score_and_the_base_is_kept(): void
    {
        Queue::fake([DraftProject::class]);
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 20]);
        $this->claude->reply($this->scoringReply(['fit_score' => 80]));

        ScoreProject::dispatchSync($project->id);

        $project->refresh();
        $this->assertSame(70, $project->fit_score);
        $this->assertSame(80, $project->score('fit_score'));
        Queue::assertNotPushed(DraftProject::class); // 70 is under the default Auto-draft at 75
    }

    public function test_the_project_page_shows_the_adjustment_and_bids_per_hour(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject(['posted_at' => now()->subHours(2)], ['bid_count' => 20], $this->scoringReply(['fit_score' => 80]));
        app(ScoreAdjuster::class)->apply($project);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->assertSee('Base score 80')
            ->assertSee('-10 20 bids (16-30)')
            ->assertSee('10 bids/hr');
    }

    // --- Bid tracking ---------------------------------------------------------

    public function test_bids_per_hour_from_snapshots_or_since_posting(): void
    {
        $project = $this->makeProject(['posted_at' => now()->subHours(4)], ['bid_count' => 8]);
        $this->assertSame(2.0, BidTracker::perHour($project), '8 bids over 4 hours since posting');

        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 10, 'recorded_at' => now()->subMinutes(30)]);
        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 22, 'recorded_at' => now()]);
        $this->assertSame(24.0, BidTracker::perHour($project), '12 new bids in 30 minutes');
    }

    public function test_each_poll_makes_one_extra_call_for_tracked_projects_and_readjusts_without_new_actions(): void
    {
        config(['bidradar.freelancer.polling_enabled' => true, 'bidradar.freelancer.oauth_token' => 'test-token']);
        app(Settings::class)->set(['polling_enabled' => true]);
        Queue::fake();

        $tracked = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 555, 'status' => 'drafted'], ['bid_count' => 10], $this->scoringReply(['fit_score' => 80]));
        app(ScoreAdjuster::class)->apply($tracked);
        $closed = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 556, 'status' => 'skipped'], ['bid_count' => 10]);

        Http::fake([
            'www.freelancer.com/api/projects/0.1/jobs/*' => Http::response(file_get_contents(base_path('tests/fixtures/freelancer/jobs.json'))),
            'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(['status' => 'success', 'result' => ['projects' => [], 'users' => null]]),
            'www.freelancer.com/api/projects/0.1/projects/?*' => Http::response(['status' => 'success', 'result' => ['projects' => [
                ['id' => 555, 'bid_stats' => ['bid_count' => 35, 'bid_avg' => 140]],
            ]]]),
        ]);

        app(Poller::class)->poll();

        $tracked->refresh();
        $this->assertSame(35, $tracked->detail('bid_count'));
        $this->assertEquals(140, $tracked->detail('bid_avg'));
        $this->assertSame(55, $tracked->fit_score, '80 - 25 for 31+ bids');
        $this->assertSame(1, BidSnapshot::query()->where('project_id', $tracked->id)->count());
        $this->assertSame(10, $closed->fresh()->detail('bid_count'), 'Skipped projects are not tracked');
        Queue::assertNothingPushed();

        $batched = Http::recorded(fn (Request $r) => str_contains($r->url(), 'projects/0.1/projects/?'));
        $this->assertCount(1, $batched);
        $this->assertStringContainsString('projects[]=555', urldecode($batched->first()[0]->url()));
        $this->assertStringNotContainsString('556', urldecode($batched->first()[0]->url()));
    }

    public function test_purge_removes_bid_history_older_than_24_hours(): void
    {
        $project = $this->makeProject();
        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 3, 'recorded_at' => now()->subHours(25)]);
        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 5, 'recorded_at' => now()->subHour()]);

        $this->artisan('bidradar:purge')->assertSuccessful();

        $this->assertSame([5], BidSnapshot::query()->pluck('bid_count')->all());
    }

    // --- Refresh button -------------------------------------------------------

    public function test_refresh_makes_one_call_and_updates_bids_score_and_suggested_bid(): void
    {
        config(['bidradar.freelancer.polling_enabled' => true, 'bidradar.freelancer.oauth_token' => 'test-token']);
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 777], ['bid_count' => 3, 'bid_avg' => 180], $this->scoringReply(['fit_score' => 80]));
        $project->drafts()->create(['proposal_text' => 'My proposal', 'bid_amount' => 171]);
        Http::fake(['www.freelancer.com/api/projects/0.1/projects/?*' => Http::response(['status' => 'success', 'result' => ['projects' => [
            ['id' => 777, 'bid_stats' => ['bid_count' => 18, 'bid_avg' => 200]],
        ]]])]);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->call('refresh')
            ->assertHasNoErrors()
            ->assertSee('Suggested bid now:')
            ->assertSee('USD 190')
            ->assertSee('draft says 171');

        Http::assertSentCount(1);
        $project->refresh();
        $this->assertSame(18, $project->detail('bid_count'));
        $this->assertSame(70, $project->fit_score);
        $this->assertEquals(190, $project->detail('suggested.amount'));
        $this->assertSame('171.00', $project->latestDraft->bid_amount, 'The draft is untouched');
    }

    public function test_refresh_finds_a_pasted_project_by_its_link(): void
    {
        config(['bidradar.freelancer.polling_enabled' => true, 'bidradar.freelancer.oauth_token' => 'test-token']);
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject(['url' => 'https://www.freelancer.com/projects/php/fix-checkout'], [], $this->scoringReply());
        Http::fake(['www.freelancer.com/api/projects/0.1/projects/?*' => Http::response(['status' => 'success', 'result' => ['projects' => [
            ['id' => 999, 'bid_stats' => ['bid_count' => 7, 'bid_avg' => 150]],
        ]]])]);

        Livewire::test(ProjectShow::class, ['project' => $project])->call('refresh')->assertHasNoErrors();

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'seo_urls[]=php/fix-checkout'));
        $this->assertSame(7, $project->fresh()->detail('bid_count'));
    }

    public function test_refresh_needs_api_permission_and_sends_nothing_without_it(): void
    {
        config(['bidradar.freelancer.polling_enabled' => false]);
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject(['source' => 'freelancer', 'freelancer_id' => 777], [], $this->scoringReply());
        Http::fake();

        Livewire::test(ProjectShow::class, ['project' => $project])->call('refresh')->assertHasErrors('refresh');

        Http::assertNothingSent();
    }
}
