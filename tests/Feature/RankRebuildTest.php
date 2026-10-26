<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Livewire\ProjectShow;
use App\Livewire\SettingsPage;
use App\Models\User;
use App\Services\ScoreAdjuster;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RankRebuildTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): array
    {
        return array_merge(['payment_verified' => true, 'rating' => 4.9, 'projects' => 5, 'country' => 'US'], $overrides);
    }

    private function adjust(array $scoring, array $details, string $text = 'Fix my checkout'): array
    {
        return app(ScoreAdjuster::class)->adjust(
            array_merge(['fit_score' => 60], $scoring),
            array_merge(['type' => 'fixed', 'budget_min' => 100, 'budget_max' => 200, 'exchange_rate' => 1, 'bid_count' => 5, 'client' => $this->client()], $details),
            $text,
        );
    }

    private function labels(array $result): array
    {
        return array_map(fn ($a) => $a['label'].' '.$a['points'], $result['adjustments']);
    }

    public function test_mode_off_adds_nothing_and_asks_nothing_extra(): void
    {
        $result = $this->adjust(['quick_fix' => true], ['client' => $this->client(['payment_verified' => false])], 'cheap job');

        $this->assertSame(60, $result['score']);
        $this->assertArrayNotHasKey('quick_fix', Scorer::schema(false)['properties']);
    }

    public function test_each_rank_rebuild_adjustment(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true, 'min_budget_usd' => 25]);

        $this->assertSame(['quick fix (1-2 days) 10', 'client 4.8+ with 3+ projects 10'], $this->labels($this->adjust(['quick_fix' => true], [])));

        $this->assertSame([], $this->labels($this->adjust([], ['client' => $this->client(['rating' => 4.7])])));
        $this->assertSame([], $this->labels($this->adjust([], ['client' => $this->client(['projects' => 2])])));

        $this->assertContains('payment not verified -15', $this->labels($this->adjust([], ['client' => $this->client(['payment_verified' => false])])));

        $this->assertContains('price shopper ("cheapest") -20', $this->labels($this->adjust([], [], 'Need the cheapest developer')));
        $this->assertContains('price shopper ("low budget") -20', $this->labels($this->adjust([], [], 'Low budget, quick job')));
        $this->assertContains('budget top under minimum ($20) -20', $this->labels($this->adjust([], ['budget_min' => 10, 'budget_max' => 20])));
        $this->assertSame(1, count(array_filter($this->labels($this->adjust([], ['budget_max' => 20, 'budget_min' => 10], 'cheap')), fn ($l) => str_ends_with($l, '-20'))), 'One -20 for price signals, not two');

        $this->assertContains('large, vague or off-niche -20', $this->labels($this->adjust(['large_vague_or_off_niche' => true], [])));
    }

    public function test_adjustments_combine_with_the_bid_count_band(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true]);

        $result = $this->adjust(['quick_fix' => true], ['bid_count' => 20]);

        $this->assertSame(70, $result['score']); // 60 - 10 (bids) + 10 (quick fix) + 10 (trusted client)
    }

    public function test_scoring_asks_for_the_flags_only_when_the_mode_is_on_and_the_page_lists_them(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true]);
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject([], ['bid_count' => 5, 'client' => $this->client()]);
        $this->claude->reply($this->scoringReply(['fit_score' => 60, 'quick_fix' => true, 'large_vague_or_off_niche' => false]));

        ScoreProject::dispatchSync($project->id);

        $request = $this->claude->requests[0];
        $this->assertContains('quick_fix', $request->schema['required']);
        $this->assertStringContainsString('quick_fix: true if this is a small, well-defined fix', $request->system[0]['text']);
        $this->assertSame(80, $project->fresh()->fit_score);

        Livewire::test(ProjectShow::class, ['project' => $project->fresh()])
            ->assertSee('Base score 60')
            ->assertSee('+10 quick fix (1-2 days)')
            ->assertSee('+10 client 4.8+ with 3+ projects');
    }

    public function test_the_ai_is_told_not_to_penalise_bids_or_payment_which_code_already_adjusts(): void
    {
        $project = $this->makeProject();
        $this->claude->reply($this->scoringReply());

        app(Scorer::class)->score($project);

        $prompt = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringNotContainsString('very high bid counts', $prompt);
        $this->assertStringNotContainsString('unverified payment', $prompt);
        $this->assertStringContainsString('Do not adjust for the number of bids or the client\'s payment status', $prompt);
    }

    public function test_the_mode_is_a_settings_checkbox(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)->assertSee('Rank rebuild mode')->set('form.rank_rebuild', true)->call('save')->assertHasNoErrors();

        $this->assertTrue(app(Settings::class)->get('rank_rebuild'));
    }
}
