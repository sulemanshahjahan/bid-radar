<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Livewire\ProjectShow;
use App\Livewire\StatsPage;
use App\Models\Draft;
use App\Models\Project;
use App\Models\User;
use App\Services\Claude\ClaudeException;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AlertFirstTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{text: string, silent: bool, drafts: int}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        app(Settings::class)->set(['turnaround_notes' => 'Fix: 1-2 days']);
        Http::fake(['api.telegram.org/*' => function (Request $r) {
            // Record how many drafts existed at the moment each message went out.
            $this->sent[] = ['text' => $r['text'], 'silent' => $r['disable_notification'], 'drafts' => Draft::query()->count()];

            return Http::response(['ok' => true]);
        }]);
    }

    private function liveHighFit(array $details = []): Project
    {
        return $this->makeProject(['source' => 'freelancer'], array_merge(['bid_count' => 3], $details));
    }

    public function test_the_alert_goes_out_before_the_draft_is_written_then_the_draft_follows(): void
    {
        $project = $this->liveHighFit();
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $this->assertCount(3, $this->sent);
        $this->assertSame(0, $this->sent[0]['drafts'], 'Alert sent before any draft existed');
        $this->assertStringContainsString('Writing the draft now', $this->sent[0]['text']);
        $this->assertFalse($this->sent[0]['silent'], 'Top project keeps its sound');

        $this->assertSame(1, $this->sent[1]['drafts']);
        $this->assertSame(htmlspecialchars($this->draftReply()['proposal_text'], ENT_QUOTES | ENT_HTML5), $this->sent[1]['text']);
        $this->assertStringStartsWith('Bid: <code>171</code>', $this->sent[2]['text']);
        $this->assertTrue($this->sent[1]['silent'] && $this->sent[2]['silent']);
    }

    public function test_a_fresh_high_fit_project_alerts_with_sound_even_with_many_bids(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:20:04'));
        $fresh = $this->liveHighFit(['bid_count' => 48]);
        $fresh->forceFill(['posted_at' => now()->subSeconds(47)])->save();
        $this->claude->reply($this->scoringReply(['fit_score' => 82]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($fresh->id);

        $this->assertFalse($this->sent[0]['silent'], '48 bids 47s after posting: early, so it rings');

        $this->sent = [];
        $old = $this->liveHighFit(['bid_count' => 48]);
        $old->forceFill(['posted_at' => now()->subMinutes(30)])->save();
        $this->claude->reply($this->scoringReply(['fit_score' => 100]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($old->id);

        $this->assertSame(75, $old->fresh()->fit_score, '100 - 25 for 31+ bids');
        $this->assertTrue($this->sent[0]['silent'], 'Older and crowded: silent alert');
    }

    public function test_a_failed_draft_sends_a_short_no_draft_follow_up(): void
    {
        $project = $this->liveHighFit();
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))
            ->fail(new ClaudeException('Claude API error 529: overloaded_error', 529, null, 'overloaded_error', 'Overloaded'));

        ScoreProject::dispatchSync($project->id);

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('No draft for', $this->sent[1]['text']);
        $this->assertStringContainsString('Drafting failed', $this->sent[1]['text']);
        $this->assertTrue($this->sent[1]['silent']);
    }

    public function test_cap_reached_at_scoring_sends_one_alert_that_says_so_and_no_promise(): void
    {
        app(Settings::class)->set(['daily_spend_cap_usd' => 0]);
        $project = $this->liveHighFit();
        $this->claude->reply($this->scoringReply(['fit_score' => 90]));

        ScoreProject::dispatchSync($project->id);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('cap reached', $this->sent[0]['text']);
        $this->assertStringNotContainsString('Writing the draft', $this->sent[0]['text']);
    }

    public function test_a_failed_check_is_flagged_in_the_bid_message(): void
    {
        $project = $this->liveHighFit();
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))
            ->reply($this->draftReply(['proposal_text' => 'Too short.']), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => 'Still too short.']), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $this->assertStringContainsString('failed some checks', end($this->sent)['text']);
    }

    // --- Timeline --------------------------------------------------------------

    public function test_each_step_is_timestamped_and_shown_on_the_project_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $project = $this->liveHighFit();
        $project->forceFill(['posted_at' => now()->subSeconds(62)])->save();
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $project->refresh();
        $this->assertNotNull($project->scored_at);
        $this->assertNotNull($project->alerted_at);
        $this->assertNotNull($project->drafted_at);
        $this->assertSame(['Posted', 'Found', 'Scored', 'Alerted', 'Draft ready'], array_column($project->timeline(), 'label'));
        $this->assertSame(62, $project->timeline()[1]['seconds']);

        $this->actingAs(User::factory()->create());
        Livewire::test(ProjectShow::class, ['project' => $project])->assertSee('Timeline')->assertSee('+62s')->assertSee('Draft ready');
    }

    public function test_durations_read_naturally(): void
    {
        $this->assertSame('45s', Project::duration(45));
        $this->assertSame('2m 05s', Project::duration(125));
        $this->assertSame('3h 10m', Project::duration(11400));
    }

    public function test_stats_show_median_speed_per_step(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        foreach ([[60, 10, 30, 90], [120, 20, 40, null], [30, 6, null, 50]] as [$found, $alert, $draft, $bid]) {
            $posted = now()->subHour();
            $p = Project::query()->create(['source' => 'freelancer', 'title' => 'P', 'posted_at' => $posted, 'ai_state' => 'drafted']);
            $p->forceFill([
                'created_at' => $posted->copy()->addSeconds($found),
                'alerted_at' => $posted->copy()->addSeconds($found + $alert),
                'drafted_at' => $draft ? $posted->copy()->addSeconds($found + $alert + $draft) : null,
                'bid_placed_at' => $bid ? $posted->copy()->addSeconds($found + $alert + $bid) : null,
            ])->save();
        }

        $speed = collect(StatsPage::speed())->keyBy('label');

        $this->assertSame(60, $speed['Posted to found']['median']);
        $this->assertSame(10, $speed['Found to alert']['median']);
        $this->assertSame(35, $speed['Alert to draft ready']['median']);
        $this->assertSame(2, $speed['Alert to you bid']['count']);

        $this->actingAs(User::factory()->create());
        $this->get('/stats')->assertSee('Speed, last 7 days')->assertSee('Posted to found');
    }
}
