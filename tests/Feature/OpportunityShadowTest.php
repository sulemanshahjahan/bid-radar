<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Livewire\OpportunityList;
use App\Livewire\ProjectList;
use App\Livewire\ProjectShow;
use App\Livewire\SettingsPage;
use App\Models\BidSnapshot;
use App\Models\DailyStat;
use App\Models\PastProject;
use App\Models\Project;
use App\Models\User;
use App\Services\Backup\ScrubbedBackup;
use App\Services\Drafting\Drafter;
use App\Services\Opportunity\Opportunity;
use App\Services\Opportunity\OpportunityInput;
use App\Services\Opportunity\OpportunityScorer;
use App\Services\Opportunity\ProfileEvidence;
use App\Services\Opportunity\ShadowRanking;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Shadow mode: the Opportunity score is display-only, stores nothing and cannot affect production decisions. */
class OpportunityShadowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'SECRET-BRIEF-TEXT';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $this->actingAs(User::factory()->create());
        app(Settings::class)->set(['hourly_rate_usd' => 25]);
    }

    private function scored(array $over = [], array $details = [], array $scoring = []): Project
    {
        return $this->makeProject(array_merge([
            'source' => 'freelancer', 'title' => 'Elementor CSS mismatch '.self::SECRET, 'description' => 'Elementor live page CSS differs. '.self::SECRET,
            'posted_at' => now()->subMinutes(20), 'scored_at' => now()->subMinutes(19),
        ], $over), array_merge(['budget_min' => 50, 'budget_max' => 110, 'bid_count' => 6, 'skills' => ['Elementor', 'CSS']], $details),
            array_merge(['fit_score' => 60, 'category' => 'fix', 'estimated_hours' => 2, 'quick_fix' => true, 'deliverables' => ['Fix']], $scoring));
    }

    // --- Production behaviour is unchanged -------------------------------------------------

    public function test_scoring_alerts_and_decisions_are_identical_with_the_shadow_engine_on_or_off(): void
    {
        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        $sent = [];
        Http::fake(['api.telegram.org/*' => function (Request $r) use (&$sent) {
            $sent[] = $r['disable_notification'];

            return Http::response(['ok' => true]);
        }]);

        $run = function (bool $enabled) use (&$sent) {
            config(['bidradar.opportunity.enabled' => $enabled]);
            $sent = [];
            $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 3]);
            $this->claude->reply($this->scoringReply(['fit_score' => 60]));
            ScoreProject::dispatchSync($project->id);
            $project->refresh();
            $decision = $project->decision;
            unset($decision['decided_at']);

            return [$project->fit_score, $project->ai_state, $project->status, $decision, $sent, $project->drafts()->count()];
        };

        app(Settings::class)->set(['quiet_alerts' => true]);
        $this->assertSame($run(false), $run(true));
        $this->assertCount(2, $this->claude->requests, 'One scoring call per project; the shadow engine adds none');
    }

    public function test_the_feed_shows_no_shadow_score_and_keeps_its_order(): void
    {
        $this->scored(['title' => 'Higher existing score'], [], ['fit_score' => 90]);
        $this->scored(['title' => 'Lower existing score'], [], ['fit_score' => 55]);

        Livewire::test(ProjectList::class)
            ->assertSeeInOrder(['Higher existing score', 'Lower existing score'])
            ->assertDontSee('Opportunity')->assertDontSee('shadow');
    }

    public function test_no_ai_or_freelancer_calls_are_made_by_the_shadow_views(): void
    {
        $p = $this->scored();
        Http::fake();

        Livewire::test(OpportunityList::class)->assertOk();
        Livewire::test(ProjectShow::class, ['project' => $p])->assertOk();
        $this->artisan('bidradar:opportunity-compare')->assertSuccessful();

        $this->assertSame([], $this->claude->requests);
        Http::assertNothingSent();
    }

    // --- The experimental views ---------------------------------------------------------------

    public function test_the_shadow_page_ranks_by_opportunity_next_to_the_existing_score(): void
    {
        $this->scored(['title' => 'Specialist fix'], ['budget_min' => 60, 'budget_max' => 120], ['fit_score' => 55]);
        $this->scored(['title' => 'Big vague build', 'description' => 'Build us an app. Details later.'], ['budget_min' => 100, 'budget_max' => 200, 'skills' => ['PHP']],
            ['fit_score' => 85, 'category' => 'new_build', 'estimated_hours' => 80, 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => []]);

        Livewire::test(OpportunityList::class)
            ->assertSee('Shadow mode')->assertSee('not chances of winning')
            ->assertSeeInOrder(['Specialist fix', 'Big vague build'])
            ->assertSee('was 55')
            ->set('sort', 'existing')
            ->assertSeeInOrder(['Big vague build', 'Specialist fix']);
    }

    public function test_recommended_puts_class_a_above_a_higher_scoring_b_and_shows_the_shortlist_count(): void
    {
        PastProject::query()->create(['category' => 'fix', 'problem' => 'Elementor styling conflict on the live site', 'what_i_did' => 'Fixed the CSS', 'result' => 'Positive feedback']);
        // Good pay, new client: A. Better client, poor pay: higher score but B ("far below target").
        $this->scored(['title' => 'Paid Elementor fix'], ['budget_min' => 60, 'budget_max' => 120, 'bid_count' => 40, 'client' => []],
            ['fit_score' => 70, 'estimated_hours' => 2]);
        $this->scored(['title' => 'Cheap Elementor fix'], ['budget_min' => 10, 'budget_max' => 12, 'client' => ['payment_verified' => true, 'rating' => 5.0, 'reviews' => 20, 'projects' => 25]],
            ['fit_score' => 90, 'estimated_hours' => 3]);
        $rows = collect(app(ShadowRanking::class)->rows())->keyBy(fn ($r) => $r['project']->title);
        $this->assertSame('A', $rows['Paid Elementor fix']['opportunity']->class);
        $this->assertSame('B', $rows['Cheap Elementor fix']['opportunity']->class);
        $this->assertGreaterThan($rows['Paid Elementor fix']['opportunity']->score, $rows['Cheap Elementor fix']['opportunity']->score, 'precondition');

        Livewire::test(OpportunityList::class)
            ->assertSeeInOrder(['Paid Elementor fix', 'Cheap Elementor fix'])
            ->assertSeeHtml('2 shortlisted')->assertSee('/ 2 evaluated')
            ->assertSee('Budget far below target')->assertSee(Opportunity::SKIP)
            ->assertSee('% of target')
            ->set('sort', 'opportunity')
            ->assertSeeInOrder(['Cheap Elementor fix', 'Paid Elementor fix']);
    }

    public function test_an_empty_shortlist_says_so_without_hiding_that_projects_exist(): void
    {
        $this->scored(['title' => 'Vague build', 'description' => 'Build an app.'], ['skills' => ['PHP']],
            ['fit_score' => 10, 'category' => 'new_build', 'quick_fix' => false, 'large_vague_or_off_niche' => true, 'deliverables' => []]);

        Livewire::test(OpportunityList::class)->assertSee('Vague build')
            ->set('shortlist', true)->assertDontSee('Vague build')->assertSee('No A or B projects right now');
    }

    public function test_the_shadow_page_hides_purged_filtered_and_old_projects(): void
    {
        $this->scored(['title' => 'Purged one', 'purged_at' => now()]);
        $this->scored(['title' => 'Filtered one', 'ai_state' => 'filtered']);
        $this->scored(['title' => 'Old one'])->forceFill(['created_at' => now()->subHours(25)])->save();

        Livewire::test(OpportunityList::class)->assertDontSee('Purged one')->assertDontSee('Filtered one')->assertDontSee('Old one');
    }

    public function test_decision_time_view_uses_the_score_bidradar_acted_on(): void
    {
        $p = $this->scored(['decision' => ['score' => 71]], ['bid_count' => 80], ['fit_score' => 60]);
        $p->forceFill(['fit_score' => 35])->save(); // later bid penalties
        BidSnapshot::query()->create(['project_id' => $p->id, 'bid_count' => 4, 'recorded_at' => now()->subMinutes(19)]);

        Livewire::test(OpportunityList::class)->assertSee('was 35')->set('at', 'decision')->assertSee('was 71');
    }

    public function test_the_project_page_shows_the_shadow_panel(): void
    {
        $p = $this->scored();

        Livewire::test(ProjectShow::class, ['project' => $p])
            ->assertSee('Opportunity')->assertSee('shadow')->assertSee('not a chance of winning')->assertSee('Technical and scope match');
    }

    public function test_turning_the_flag_off_removes_every_shadow_view(): void
    {
        config(['bidradar.opportunity.enabled' => false]);
        $p = $this->scored();

        $this->get('/opportunities')->assertNotFound();
        Livewire::test(ProjectShow::class, ['project' => $p])->assertDontSee('Technical and scope match');
        $this->get('/')->assertOk()->assertDontSee('Picks');
        $this->artisan('bidradar:opportunity-compare')->expectsOutputToContain('is off')->assertSuccessful();
    }

    // --- Failures stay contained --------------------------------------------------------------

    public function test_a_failing_evaluation_never_breaks_a_page_and_logs_no_project_text(): void
    {
        $p = $this->scored();
        $this->app->instance(OpportunityScorer::class, new class(app(Settings::class)) extends OpportunityScorer
        {
            public function evaluate(OpportunityInput $in, ProfileEvidence $profile): Opportunity
            {
                throw new RuntimeException('boom with '.$in->text);
            }
        });
        Log::spy();

        Livewire::test(ProjectShow::class, ['project' => $p])->assertOk()->assertDontSee('Technical and scope match');
        Livewire::test(OpportunityList::class)->assertOk()->assertSee('1 not evaluated');

        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'opportunity evaluation failed'
            && $ctx === ['project_id' => $p->id, 'error' => RuntimeException::class]);
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_contains((string) $m, self::SECRET)), \Mockery::any()]);
    }

    public function test_a_failing_evaluation_never_breaks_the_hourly_counts(): void
    {
        $this->app->instance(OpportunityScorer::class, new class(app(Settings::class)) extends OpportunityScorer
        {
            public function evaluate(OpportunityInput $in, ProfileEvidence $profile): Opportunity
            {
                throw new RuntimeException('boom');
            }
        });
        $p = $this->scored(['created_at' => Carbon::parse('2026-10-27 14:05:00'), 'decision' => ['score' => 60]]);
        $p->forceFill(['created_at' => Carbon::parse('2026-10-27 14:05:00')])->save();

        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $this->assertSame(1, (int) DailyStat::query()->where('metric', 'discovered')->sum('value'));
        $this->assertSame(1, (int) DailyStat::query()->where('metric', 'opportunity')->where('key', 'error|medium')->sum('value'));
    }

    // --- Privacy and retention ----------------------------------------------------------------

    public function test_nothing_is_stored_no_new_tables_or_project_columns(): void
    {
        $before = $this->snapshotDatabase();
        $p = $this->scored();

        Livewire::test(OpportunityList::class);
        Livewire::test(ProjectShow::class, ['project' => $p]);
        $this->artisan('bidradar:opportunity-compare --titles');

        $this->assertSame($before['tables'], Schema::getTableListing());
        $this->assertSame($before['columns'], Schema::getColumnListing('projects'));
        $this->assertSame($p->getRawOriginal('decision'), $p->fresh()->getRawOriginal('decision'));
        $this->assertSame($p->getRawOriginal('scoring') !== null, $p->fresh()->getRawOriginal('scoring') !== null);
        $this->assertSame(0, DB::table('settings')->where('key', 'like', '%opportunit%')->count());
    }

    public function test_hourly_opportunity_counts_hold_only_class_and_band(): void
    {
        $p = $this->scored(['decision' => ['score' => 70]]);
        $p->forceFill(['created_at' => Carbon::parse('2026-10-27 14:05:00')])->save();

        $this->artisan('bidradar:daily-stats')->assertSuccessful();

        $keys = DailyStat::query()->where('metric', 'opportunity')->pluck('key')->all();
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^(A|B|C|error)\|(high|medium|low|unscored)$/', $key);
        }
        $this->assertSame(0, DailyStat::query()->where('key', 'like', '%'.self::SECRET.'%')->count());
    }

    public function test_the_purge_leaves_nothing_to_evaluate_and_backups_still_verify(): void
    {
        $p = $this->scored();
        $p->forceFill(['created_at' => now()->subHours(25), 'status' => 'drafted'])->save();
        $this->artisan('bidradar:purge')->assertSuccessful();

        $this->assertNull(app(ShadowRanking::class)->forProject($p->fresh()));
        $this->assertSame([], app(ShadowRanking::class)->rows());
        $this->assertContains('scoring', ScrubbedBackup::RESTRICTED_COLUMNS, 'Every input the engine reads from a project is wiped by the purge and the backup');
        $this->assertContains('details', ScrubbedBackup::RESTRICTED_COLUMNS);
        $this->assertContains('bid_snapshots', ScrubbedBackup::EMPTIED_TABLES);
    }

    public function test_the_compare_command_prints_ids_not_titles_unless_asked(): void
    {
        $this->scored();

        $this->artisan('bidradar:opportunity-compare')->doesntExpectOutputToContain(self::SECRET)->expectsOutputToContain('Sample limitation')->assertSuccessful();
        $this->artisan('bidradar:opportunity-compare --titles')->expectsOutputToContain('Elementor CSS mismatch')->assertSuccessful();
        $this->artisan('bidradar:opportunity-compare --synthetic')->expectsOutputToContain('SYNTHETIC')->assertSuccessful();
    }

    public function test_skill_rankings_are_editable_with_a_date_and_never_reach_the_drafting_prompt(): void
    {
        Livewire::test(SettingsPage::class)->set('form.skill_rankings', "CSS3 | 2\nHTML5 | 4")->set('form.skill_rankings_as_of', '2026-10-27')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['CSS3 | 2', 'HTML5 | 4'], app(Settings::class)->list('skill_rankings'));
        Livewire::test(SettingsPage::class)->set('form.skill_rankings_as_of', 'last week')->call('save')->assertHasErrors('form.skill_rankings_as_of');

        $factBank = app(Drafter::class)->factBank();
        $this->assertStringNotContainsString('CSS3 | 2', $factBank);
        $this->assertStringNotContainsString('top 2', strtolower($factBank));
    }

    /** @return array{tables: list<string>, columns: list<string>} */
    private function snapshotDatabase(): array
    {
        return ['tables' => Schema::getTableListing(), 'columns' => Schema::getColumnListing('projects')];
    }
}
