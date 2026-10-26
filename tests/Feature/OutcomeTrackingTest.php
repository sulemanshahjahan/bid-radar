<?php

namespace Tests\Feature;

use App\Livewire\ProjectShow;
use App\Livewire\StatsPage;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OutcomeTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00')); // a Wednesday
        $this->actingAs(User::factory()->create());
    }

    private function bid(string $category, int $bidsAtBid, array $extra = []): Project
    {
        return Project::query()->create(array_merge([
            'source' => 'freelancer', 'title' => 'P', 'category' => $category, 'status' => 'bid_placed',
            'bid_placed_at' => now()->subDays(10), 'bid_count_at_bid' => $bidsAtBid, 'ai_state' => 'drafted',
        ], $extra));
    }

    // --- Bid placed, Viewed, Chat started ----------------------------------------

    public function test_bid_placed_records_my_rank_and_total_bids(): void
    {
        $project = $this->makeProject([], ['bid_count' => 33], $this->scoringReply());

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->assertSet('totalBids', 33) // prefilled from the latest count
            ->set('myBid', 175)->set('myRank', 12)->set('totalBids', 40)
            ->call('markBidPlaced')->assertHasNoErrors();

        $project->refresh();
        $this->assertSame('bid_placed', $project->status);
        $this->assertSame(12, $project->my_bid_rank);
        $this->assertSame(40, $project->bid_count_at_bid);
    }

    public function test_rank_and_total_are_optional_but_validated(): void
    {
        $project = $this->makeProject([], ['bid_count' => 7], $this->scoringReply());

        Livewire::test(ProjectShow::class, ['project' => $project])->set('myBid', 100)->set('myRank', '')->set('totalBids', '')->call('markBidPlaced')->assertHasNoErrors();
        $this->assertNull($project->fresh()->my_bid_rank);
        $this->assertSame(7, $project->fresh()->bid_count_at_bid);

        Livewire::test(ProjectShow::class, ['project' => $project])->set('myBid', 100)->set('myRank', 0)->call('markBidPlaced')->assertHasErrors('myRank');
    }

    public function test_viewed_and_chat_started_toggles(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $c = Livewire::test(ProjectShow::class, ['project' => $project])->assertSee('Viewed?')->assertSee('Chat started?');

        $c->call('setOutcome', 'viewed', true)->call('setOutcome', 'chat_started', false);
        $this->assertTrue($project->fresh()->viewed);
        $this->assertFalse($project->fresh()->chat_started);

        $c->call('setOutcome', 'viewed', null);
        $this->assertNull($project->fresh()->viewed);

        $c->call('setOutcome', 'status', true)->assertStatus(422);
    }

    public function test_won_records_when_and_lost_clears_it(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $c = Livewire::test(ProjectShow::class, ['project' => $project]);

        $c->call('setStatus', 'won');
        $this->assertEquals(now(), $project->fresh()->won_at);

        $c->call('setStatus', 'lost');
        $this->assertNull($project->fresh()->won_at);
    }

    // --- Stats page -----------------------------------------------------------------

    public function test_bid_count_buckets(): void
    {
        $this->assertSame('0-10', StatsPage::bucket(0));
        $this->assertSame('0-10', StatsPage::bucket(10));
        $this->assertSame('11-20', StatsPage::bucket(11));
        $this->assertSame('21-40', StatsPage::bucket(40));
        $this->assertSame('41+', StatsPage::bucket(41));
        $this->assertSame('unknown', StatsPage::bucket(null));
    }

    public function test_rates_by_category_and_bucket_week_count_and_recent_wins(): void
    {
        $this->bid('fix', 5, ['viewed' => true, 'chat_started' => true, 'status' => 'won', 'won_at' => now()->subDays(3), 'title' => 'Recent win']);
        $this->bid('fix', 8, ['viewed' => true]);
        $this->bid('fix', 30, ['viewed' => false]);
        $this->bid('speed', 50, ['status' => 'won', 'won_at' => now()->subDays(40), 'title' => 'Old win']);
        $this->bid('speed', 15, ['bid_placed_at' => now()->subDay()]); // this week (Mon 26 Oct onward)
        $this->bid('fix', 9, ['bid_placed_at' => now()->startOfWeek()]);

        $c = Livewire::test(StatsPage::class);
        $view = $c->viewData('byCategory');
        $this->assertSame(['label' => 'Fix', 'bids' => 4, 'view_rate' => 50, 'chat_rate' => 25, 'win_rate' => 25], $view['fix']);
        $this->assertSame(50, $view['speed']['win_rate']);

        $buckets = $c->viewData('byBucket');
        $this->assertSame(3, $buckets['0-10']['bids']);
        $this->assertSame(67, $buckets['0-10']['view_rate']);
        $this->assertSame(1, $buckets['41+']['bids']);

        $c->assertSee('Bids this week')->assertViewHas('thisWeek', 2)->assertViewHas('weeklyCap', 50)
            ->assertSee('Recent win')->assertDontSee('Old win');
    }

    public function test_stats_page_is_in_the_nav_and_renders_empty(): void
    {
        $this->get('/stats')->assertOk()->assertSee('No bids placed yet.')->assertSee('No wins marked in the last 30 days.');
        $this->get('/')->assertSee(route('stats'));
    }
}
