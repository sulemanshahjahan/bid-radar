<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Models\Setting;
use App\Services\BidTracker;
use App\Services\Freelancer\RulesFilter;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Project #89 (17 WordPress fixes, 48 bids 47s after posting) scored 7 and never alerted:
 * bids and vagueness were each counted twice. These keep each signal counted once.
 */
class FairScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoring_does_not_see_bid_count_or_payment_status_but_drafting_does(): void
    {
        $project = $this->makeProject([], ['bid_count' => 12, 'bid_avg' => 112]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $scoring = $this->claude->requests[0]->userContent;
        $this->assertStringNotContainsString('Bids so far', $scoring);
        $this->assertStringNotContainsString('payment', $scoring);
        $this->assertStringContainsString('Client: rating 4.8, country US', $scoring);

        $drafting = $this->claude->requests[1]->userContent;
        $this->assertStringContainsString('Bids so far: 12', $drafting);
        $this->assertStringContainsString('payment verified', $drafting);
    }

    public function test_a_live_project_found_in_its_first_minutes_has_no_bid_penalty_until_it_ages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:20:04'));
        $project = $this->makeProject(['source' => 'freelancer', 'posted_at' => now()->subSeconds(47)], ['bid_count' => 48]);
        $this->claude->reply($this->scoringReply(['fit_score' => 80]))->reply($this->draftReply(), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $this->assertSame(80, $project->fresh()->fit_score);
        $this->assertSame([], $project->fresh()->score('adjustments'));

        Carbon::setTestNow(now()->addMinutes(10));
        app(BidTracker::class)->record($project->fresh(), 90, 110.0);

        $this->assertSame(55, $project->fresh()->fit_score);
        $this->assertSame('90 bids (31+)', $project->fresh()->score('adjustments')[0]['label']);
    }

    public function test_pasted_projects_keep_the_bid_penalty(): void
    {
        $project = $this->makeProject(['posted_at' => now()], ['bid_count' => 48]);
        $this->claude->reply($this->scoringReply(['fit_score' => 80]));

        ScoreProject::dispatchSync($project->id);

        $this->assertSame(55, $project->fresh()->fit_score);
    }

    public function test_vagueness_is_marked_down_once_and_short_fix_briefs_are_normal(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true]);
        $project = $this->makeProject();
        $this->claude->reply($this->scoringReply());

        ScoreProject::dispatchSync($project->id);

        $prompt = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('true if the job is large (more than about a week of work) or outside the', $prompt);
        $this->assertStringContainsString('A short or vague brief alone is not enough', $prompt);
        $this->assertStringContainsString('the proposal can ask what the fixes are', $prompt);
    }

    // --- Basic SEO ---------------------------------------------------------------

    public function test_basic_seo_projects_pass_the_filter_but_local_seo_and_backlinks_do_not(): void
    {
        $rules = app(RulesFilter::class);
        $mapped = fn (string $text, array $skills) => [
            'title' => $text, 'description' => '', 'posted_at' => null,
            'details' => ['skills' => $skills, 'budget_min' => 50, 'budget_max' => 200, 'bid_count' => 5, 'type' => 'fixed', 'exchange_rate' => 1],
        ];

        $this->assertContains('SEO', app(Settings::class)->list('target_skills'));
        $this->assertNull($rules->reject($mapped('Fix on-page SEO on my site', ['SEO'])));
        $this->assertSame('Excluded keyword: local SEO', $rules->reject($mapped('Local SEO for my shop', ['SEO'])));
        $this->assertSame('Excluded keyword: backlinks', $rules->reject($mapped('Need 500 backlinks', ['SEO'])));
    }

    public function test_the_migration_updates_settings_that_were_already_saved(): void
    {
        app(Settings::class)->set([
            'target_skills' => ['WordPress', 'PHP'],
            'exclude_keywords' => ['SEO', 'local SEO', 'casino'],
            'services_offered' => 'WordPress fixes',
        ]);

        (require database_path('migrations/2026_11_01_000100_offer_basic_seo.php'))->up();

        $value = fn (string $key) => Setting::query()->where('key', $key)->value('value');
        $this->assertSame(['WordPress', 'PHP', 'SEO'], $value('target_skills'));
        $this->assertSame(['local SEO', 'casino'], $value('exclude_keywords'));
        $this->assertSame("WordPress fixes\nBasic SEO.", $value('services_offered'));

        (require database_path('migrations/2026_11_01_000100_offer_basic_seo.php'))->up();
        $this->assertSame(['WordPress', 'PHP', 'SEO'], $value('target_skills'), 'Running twice changes nothing');
        $this->assertSame("WordPress fixes\nBasic SEO.", $value('services_offered'));
    }
}
