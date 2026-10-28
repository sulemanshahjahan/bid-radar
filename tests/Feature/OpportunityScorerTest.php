<?php

namespace Tests\Feature;

use App\Models\BidSnapshot;
use App\Services\Opportunity\Capabilities;
use App\Services\Opportunity\Opportunity;
use App\Services\Opportunity\OpportunityInput;
use App\Services\Opportunity\OpportunityScorer;
use App\Services\Opportunity\ProfileEvidence;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Opportunity Priority Score (shadow mode). Synthetic data only: tests/fixtures/opportunity/cases.php. */
class OpportunityScorerTest extends TestCase
{
    use RefreshDatabase;

    private array $set;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 12:00:00'));
        $this->set = require base_path('tests/fixtures/opportunity/cases.php');
        app(Settings::class)->set(['hourly_rate_usd' => 25, 'platform_fee_percent' => 10]);
    }

    private function profile(?array $past = null, ?string $asOf = null, ?array $rankings = null): ProfileEvidence
    {
        return new ProfileEvidence($past ?? $this->set['fact_bank'],
            ProfileEvidence::parseRankings($rankings ?? app(Settings::class)->list('skill_rankings')), ProfileEvidence::parseDate((string) $asOf));
    }

    private function evaluate(string|array $case, ?ProfileEvidence $profile = null): Opportunity
    {
        $case = is_string($case) ? $this->set['cases'][$case] : $case;

        return app(OpportunityScorer::class)->evaluate(OpportunityInput::fromArray($case), $profile ?? $this->profile());
    }

    /** A case with some fields replaced (details and scoring merge). */
    private function variant(string $key, array $over = [], array $details = [], array $scoring = []): array
    {
        $case = array_merge($this->set['cases'][$key], $over);
        $case['details'] = array_merge($case['details'], $details);
        $case['scoring'] = array_merge($case['scoring'], $scoring);

        return $case;
    }

    // --- The owner's success scenarios -------------------------------------------------

    public function test_specialist_projects_are_strong_and_weak_commercial_fits_are_lower_priority(): void
    {
        $this->assertSame('A', $this->evaluate('S1')->class, 'Elementor CSS bug');
        $this->assertSame('A', $this->evaluate('S3')->class, 'HTML5 component');
        $this->assertSame('A', $this->evaluate('P_A')->class, 'Elementor live/editor mismatch');
        $this->assertSame('C', $this->evaluate('S2')->class, 'Complete Shopify store at $150');
        $this->assertSame('C', $this->evaluate('P_D')->class, 'Vague custom PHP at a low budget');
        $this->assertSame('C', $this->evaluate('S4')->class, 'Generic PHP app');
        $this->assertGreaterThan($this->evaluate('S4')->score, $this->evaluate('S1')->score);
        $this->assertGreaterThan($this->evaluate('S4')->score, $this->evaluate('S3')->score);
    }

    public function test_a_bigger_budget_alone_does_not_outrank_specialist_work(): void
    {
        $this->assertGreaterThan($this->evaluate('S4')->score, $this->evaluate('S5')->score);
        $this->assertGreaterThan($this->evaluate('S2')->score, $this->evaluate('S3')->score);
    }

    // --- Profile matching ----------------------------------------------------------------

    public function test_strong_verified_experience_gets_credit_with_the_fact_bank_proof(): void
    {
        $o = $this->evaluate('S1');

        $this->assertSame(ProfileEvidence::STRONG, $o->experience);
        $this->assertStringContainsString('Elementor styling conflict', (string) $o->proof);
        $this->assertSame('strong', $o->factors['experience']['level']);
        $this->assertNotEmpty(array_filter($o->strengths, fn ($s) => str_starts_with($s, 'Comparable past project')));
    }

    public function test_superficial_overlap_is_not_proven_experience(): void
    {
        // Only "WordPress" in common with the fact bank.
        $o = $this->evaluate(['title' => 'WordPress site update', 'description' => 'Update our WordPress pages text.', 'details' => ['budget_min' => 50, 'budget_max' => 100],
            'scoring' => ['category' => 'other', 'estimated_hours' => 2, 'deliverables' => ['Text updates']], 'bids' => 5, 'age_minutes' => 5]);

        $this->assertSame(ProfileEvidence::WEAK, $o->experience);
        $this->assertNull($o->proof);
        $this->assertEmpty(array_filter($o->strengths, fn ($s) => str_contains($s, 'past project')));
        $this->assertLessThan(0.5, $o->factors['experience']['value']);
    }

    public function test_no_history_produces_no_claims(): void
    {
        $o = $this->evaluate('S1', $this->profile(past: [], rankings: []));

        $this->assertNull($o->proof);
        $this->assertSame(ProfileEvidence::NONE, $o->experience);
        $this->assertTrue($o->factors['experience']['unknown']);
        $this->assertEmpty(array_filter($o->strengths, fn ($s) => str_contains($s, 'past project') || str_contains($s, 'ranking')));
        $this->assertNotSame('A', $o->class, 'No documented proof: never a strong specialist opportunity');
    }

    public function test_feedback_and_repeat_clients_are_only_mentioned_when_the_fact_bank_says_so(): void
    {
        $plain = $this->evaluate('S1', $this->profile(past: [['category' => 'fix', 'problem' => 'Elementor styling conflict on the live site', 'what_i_did' => 'Fixed the CSS', 'result' => 'Done']]));
        $this->assertStringNotContainsString('feedback', implode(' ', $plain->strengths));
        $this->assertStringNotContainsString('Repeat', implode(' ', $plain->strengths));

        $repeat = $this->evaluate(['title' => 'WordPress caching issue', 'description' => 'Pages show old content, cache problem.', 'details' => ['budget_min' => 40, 'budget_max' => 90],
            'scoring' => ['category' => 'fix', 'estimated_hours' => 2, 'quick_fix' => true], 'bids' => 5, 'age_minutes' => 5]);
        $this->assertContains('Repeat-client trust documented for that work', $repeat->strengths);
    }

    public function test_skill_rankings_are_dated_evidence(): void
    {
        $undated = $this->evaluate('S3', $this->profile());
        $current = $this->evaluate('S3', $this->profile(asOf: '2026-10-01'));
        $old = $this->evaluate('S3', $this->profile(asOf: '2025-01-01'));

        $this->assertStringContainsString('owner-reported, date unknown', implode(' ', $undated->strengths));
        $this->assertStringContainsString('as of 2026-10-01', implode(' ', $current->strengths));
        $this->assertStringContainsString('older than 180 days', implode(' ', $old->strengths));
        $this->assertGreaterThan($undated->factors['experience']['value'], $current->factors['experience']['value']);
        $this->assertSame($undated->factors['experience']['value'], $old->factors['experience']['value']);
    }

    public function test_rankings_parse_from_settings_lines_and_skip_bad_ones(): void
    {
        $r = ProfileEvidence::parseRankings(['CSS3 | 3', 'HTML5 | Top 6%', 'no pipe here', 'Bad | n/a', 'Too big | 150']);

        $this->assertSame(['CSS3', 'HTML5'], array_column($r, 'skill'));
        $this->assertSame([3.0, 6.0], array_column($r, 'top'));
        $this->assertContains('css', $r[0]['tags']);
    }

    // --- Technical fit ---------------------------------------------------------------------

    public function test_technical_fit_by_kind_of_work(): void
    {
        $this->assertGreaterThanOrEqual(0.9, $this->evaluate('S1')->factors['technical']['value'], 'Elementor/CSS fix');
        $this->assertGreaterThanOrEqual(0.75, $this->evaluate('S3')->factors['technical']['value'], 'Responsive HTML5');
        $this->assertGreaterThanOrEqual(0.75, $this->evaluate('X1')->factors['technical']['value'], 'Membership build is legitimate work');
        $this->assertGreaterThanOrEqual(0.75, $this->evaluate('X2')->factors['technical']['value'], 'Shopify customisation');
        $broad = $this->evaluate('S4')->factors['technical'];
        $this->assertLessThan(0.6, $broad['value'], 'Broad custom development flagged large/off-niche');
        $this->assertContains('Scoring flagged it as large or outside your services', $broad['risks']);
    }

    public function test_vague_scope_lowers_clarity_but_a_short_fix_brief_does_not(): void
    {
        $this->assertLessThan(0.5, $this->evaluate('S4')->factors['clarity']['value']);
        $shortFix = $this->variant('S1', scoring: ['quick_fix' => null, 'deliverables' => []]);
        $this->assertGreaterThanOrEqual(0.7, $this->evaluate($shortFix)->factors['clarity']['value']);
    }

    // --- Profitability --------------------------------------------------------------------

    public function test_profitability_from_budget_and_estimated_hours(): void
    {
        $this->assertSame(1.0, $this->evaluate('S5')->factors['profit']['value'], 'Budget supports the hours');
        $unrealistic = $this->evaluate('S2')->factors['profit'];
        $this->assertLessThan(0.3, $unrealistic['value']);
        $this->assertStringContainsString('Budget looks low for the effort', implode(' ', $unrealistic['risks']));
    }

    public function test_missing_budget_or_effort_is_unknown_not_profitable(): void
    {
        $noBudget = $this->evaluate($this->variant('S1', details: ['budget_min' => null, 'budget_max' => null]))->factors['profit'];
        $noHours = $this->evaluate($this->variant('S1', scoring: ['estimated_hours' => null]))->factors['profit'];

        foreach ([$noBudget, $noHours] as $f) {
            $this->assertTrue($f['unknown']);
            $this->assertSame(0.5, $f['value']);
            $this->assertEmpty($f['good']);
        }
        $this->assertNotSame('A', $this->evaluate($this->variant('S1', scoring: ['estimated_hours' => null]))->class, 'Unknown effort is never treated as profitable');
    }

    public function test_the_fee_assumption_comes_from_settings(): void
    {
        $case = $this->variant('S1', details: ['budget_min' => 60, 'budget_max' => 60], scoring: ['estimated_hours' => 2]);
        app(Settings::class)->set(['platform_fee_percent' => 0]);
        $noFee = $this->evaluate($case)->factors['profit'];
        app(Settings::class)->set(['platform_fee_percent' => 40]);
        $highFee = $this->evaluate($case)->factors['profit'];

        $this->assertGreaterThan($highFee['value'], $noFee['value']);
        $this->assertStringContainsString('after the 40% fee', implode(' ', $highFee['notes'] + $highFee['risks'] + $highFee['good']));
    }

    public function test_a_very_small_quick_fix_can_still_be_viable(): void
    {
        // $60 for about two hours of work.
        $f = $this->evaluate($this->variant('S1', details: ['budget_min' => 60, 'budget_max' => 60], scoring: ['estimated_hours' => 2]))->factors['profit'];

        $this->assertGreaterThanOrEqual(0.75, $f['value']);
    }

    public function test_hourly_projects_compare_the_rate_with_the_target(): void
    {
        $good = $this->evaluate($this->variant('S1', details: ['type' => 'hourly', 'budget_min' => 15, 'budget_max' => 30]))->factors['profit'];
        $low = $this->evaluate($this->variant('S1', details: ['type' => 'hourly', 'budget_min' => 5, 'budget_max' => 10]))->factors['profit'];

        $this->assertSame(0.9, $good['value']);
        $this->assertSame(0.3, $low['value']);
    }

    // --- Competition ----------------------------------------------------------------------

    public function test_competition_bands_never_write_a_project_off(): void
    {
        $this->assertSame(1.0, $this->evaluate($this->variant('S1', ['bids' => 4, 'age_minutes' => 30]))->factors['competition']['value']);
        $crowded = $this->evaluate($this->variant('S1', ['bids' => 140, 'age_minutes' => 60]))->factors['competition'];
        $this->assertSame(0.25, $crowded['value']);
        $this->assertStringContainsString('not unwinnable', implode(' ', $crowded['risks']));
    }

    public function test_rapid_early_bidding_is_a_crowding_signal_with_a_cap_not_a_veto(): void
    {
        $f = $this->evaluate($this->variant('S1', ['bids' => 40, 'age_minutes' => 2]))->factors['competition'];

        $this->assertSame(0.35, $f['value']);
        $this->assertFalse($f['unknown']);
        $this->assertStringContainsString('likely to get crowded', implode(' ', $f['risks']));
    }

    public function test_missing_bid_count_is_unknown_and_age_reduces_visibility(): void
    {
        $missing = $this->evaluate($this->variant('S1', ['bids' => null]))->factors['competition'];
        $this->assertTrue($missing['unknown']);
        $this->assertSame(0.5, $missing['value']);

        $old = $this->evaluate($this->variant('S1', ['bids' => 4, 'age_minutes' => 13 * 60]))->factors['competition'];
        $this->assertSame(0.8, $old['value']);
    }

    public function test_an_evaluation_at_a_past_moment_never_uses_later_bid_counts(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'posted_at' => now()->subMinutes(30), 'scored_at' => now()->subMinutes(28)],
            ['bid_count' => 90], ['fit_score' => 80, 'category' => 'fix', 'estimated_hours' => 2]);
        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 3, 'recorded_at' => now()->subMinutes(29)]);
        BidSnapshot::query()->create(['project_id' => $project->id, 'bid_count' => 90, 'recorded_at' => now()->subMinutes(1)]);

        $then = OpportunityInput::fromProject($project->fresh(), $project->scored_at);
        $now = OpportunityInput::fromProject($project->fresh());

        $this->assertSame(3, $then->bidCount);
        $this->assertSame(1, $then->ageMinutes());
        $this->assertSame(90, $now->bidCount);

        // Before any snapshot existed nothing was observed.
        $this->assertNull(OpportunityInput::fromProject($project->fresh(), now()->subMinutes(40))->bidCount);
    }

    // --- Client credibility ---------------------------------------------------------------

    public function test_client_credibility_cases(): void
    {
        $established = $this->evaluate('S1')->factors['client'];
        $this->assertGreaterThanOrEqual(0.9, $established['value']);

        $new = $this->evaluate($this->variant('S1', details: ['client' => ['payment_verified' => true]]))->factors['client'];
        $this->assertSame(0.65, $new['value'], 'New verified client: not penalised');
        $this->assertEmpty($new['risks']);

        $negative = $this->evaluate($this->variant('S1', details: ['client' => ['payment_verified' => true, 'rating' => 3.2, 'reviews' => 9]]))->factors['client'];
        $this->assertContains('Client has a low rating', $negative['risks']);
        $this->assertLessThan(0.5, $negative['value']);

        $missing = $this->evaluate($this->variant('S1', details: ['client' => []]))->factors['client'];
        $this->assertTrue($missing['unknown']);
        $this->assertSame(0.5, $missing['value']);
    }

    public function test_country_never_affects_the_client_factor(): void
    {
        $a = $this->evaluate($this->variant('S1', details: ['client' => ['payment_verified' => true, 'country' => 'India']]));
        $b = $this->evaluate($this->variant('S1', details: ['client' => ['payment_verified' => true, 'country' => 'United States']]));

        $this->assertEquals($a, $b);
    }

    // --- Scoring rules ----------------------------------------------------------------------

    public function test_weights_add_up_and_the_total_is_the_sum_of_the_factor_points(): void
    {
        $this->assertSame(100, array_sum(OpportunityScorer::weights()));
        foreach (array_keys($this->set['cases']) as $key) {
            $o = $this->evaluate($key);
            $this->assertSame($o->score, array_sum(array_column($o->factors, 'points')), $key);
            $this->assertGreaterThanOrEqual(0, $o->score);
            $this->assertLessThanOrEqual(100, $o->score);
            foreach ($o->factors as $f) {
                $this->assertLessThanOrEqual($f['weight'], $f['points']);
            }
        }
    }

    public function test_bad_weights_are_refused(): void
    {
        config(['bidradar.opportunity.weights.technical' => 30]);

        $this->expectException(InvalidArgumentException::class);
        $this->evaluate('S1');
    }

    public function test_an_empty_project_scores_predictably_within_range(): void
    {
        $o = $this->evaluate(['title' => '', 'description' => '']);

        $this->assertSame($o->score, array_sum(array_column($o->factors, 'points')));
        $this->assertSame('C', $o->class);
        $this->assertTrue($o->factors['profit']['unknown'] && $o->factors['competition']['unknown'] && $o->factors['clarity']['unknown']);
    }

    public function test_identical_input_gives_identical_results(): void
    {
        $this->assertEquals($this->evaluate('X1'), $this->evaluate('X1'));
    }

    public function test_repeating_keywords_earns_nothing_extra(): void
    {
        $once = $this->evaluate($this->variant('S1', ['description' => 'Fix the Elementor CSS on the live page.']));
        $many = $this->evaluate($this->variant('S1', ['description' => 'Fix the Elementor CSS css CSS3 styling stylesheet scss Elementor elementor on the live page.']));

        $this->assertSame($once->score, $many->score);
        $this->assertSame(Capabilities::tag('Elementor CSS'), array_values(array_intersect(Capabilities::tag('Elementor CSS css CSS3 styling Elementor'), ['elementor', 'css'])));
    }

    public function test_a_red_flag_counts_under_one_factor_only(): void
    {
        $o = $this->evaluate('X4'); // "Asks to contact off-platform on WhatsApp"
        $mentions = array_filter(array_map(fn ($f) => count(array_filter($f['risks'], fn ($r) => str_contains($r, 'WhatsApp'))), $o->factors));

        $this->assertSame(['client' => 1], $mentions);
    }

    public function test_crowding_and_missing_proof_hold_a_high_score_out_of_class_a(): void
    {
        $crowded = $this->evaluate('X3');
        $this->assertGreaterThanOrEqual(75, $crowded->score);
        $this->assertSame('B', $crowded->class);
        $this->assertContains('Not a strong specialist opportunity: competition is already heavy.', $crowded->rules);

        $this->assertNotSame('A', $this->evaluate('X5')->class, 'Keyword-stuffed vague brief');
    }

    // --- Regressions from the 28 Oct real-project evaluation ----------------------------------

    public function test_off_niche_work_is_not_lifted_by_broad_words(): void
    {
        $o = $this->evaluate('R1');

        $this->assertSame('C', $o->class);
        $this->assertContains('Low technical fit: the scoring model rates it 8/100. Capped at C.', $o->rules);
        $this->assertNotContains($o->experience, [ProfileEvidence::STRONG, ProfileEvidence::MODERATE], 'Broad speed/HTML words earn no proven match');
        $this->assertLessThan(0.4, $o->factors['experience']['value']);
    }

    public function test_a_low_fit_project_with_a_comparable_specialist_brief_can_reach_b_but_not_a(): void
    {
        $o = $this->evaluate('R2');

        $this->assertSame('B', $o->class);
        $this->assertStringContainsString('Low-fit exception', implode(' ', $o->rules));
        $this->assertContains('Experience credit limited: technical fit is weak, so past work counts for less.', $o->rules);
    }

    public function test_skill_labels_alone_make_an_uncertain_match_not_a_strong_one(): void
    {
        $o = $this->evaluate('R3');

        $this->assertSame(ProfileEvidence::UNCERTAIN, $o->experience);
        $this->assertNull($o->proof, 'An uncertain match is never shown as proof');
        $this->assertContains('Specialist match rests on Freelancer skill labels only: treated as uncertain.', $o->rules);
        $this->assertStringContainsString('skill labels', implode(' ', $o->factors['technical']['notes']));
        $this->assertNotSame('A', $o->class);
    }

    public function test_an_uncertain_match_sits_between_general_overlap_and_brief_evidence(): void
    {
        $uncertain = $this->evaluate('R3')->factors['experience']['value'];
        $general = $this->evaluate($this->variant('R3', details: ['skills' => ['Website Design']]))->factors['experience']['value'];

        $this->assertGreaterThan($general, $uncertain);
        $this->assertNotSame('A', $this->evaluate($this->variant('R3', scoring: ['fit_score' => 95, 'quick_fix' => true]))->class, 'Uncertain is never proof for A');
    }

    public function test_the_same_brief_words_prove_a_specialist_match(): void
    {
        $labels = $this->evaluate('R3');
        $brief = $this->evaluate($this->variant('R3', ['description' => 'Build a responsive HTML5 and CSS3 card section from our design.']));

        $this->assertSame(ProfileEvidence::STRONG, $brief->experience);
        $this->assertGreaterThan($labels->factors['experience']['value'], $brief->factors['experience']['value']);
    }

    public function test_related_frontend_words_count_as_one_family(): void
    {
        $one = $this->evaluate($this->variant('S3', ['description' => 'Build a responsive card section in CSS3.']));
        $many = $this->evaluate($this->variant('S3', ['description' => 'Build a responsive card section in CSS3, HTML5, SCSS, markup, layout, front-end templates.']));

        $this->assertSame($one->factors['experience']['value'], $many->factors['experience']['value']);
    }

    public function test_email_work_is_not_an_off_platform_warning_and_vague_flags_count_once(): void
    {
        $o = $this->evaluate('R4');
        $concerns = OpportunityScorer::concerns($this->set['cases']['R4']['scoring']['red_flags']);

        $this->assertSame([], $concerns['client']);
        $this->assertSame(['vague requirements'], array_keys($concerns['clarity']));
        $this->assertSame(0.75, $o->factors['clarity']['value'], 'Quick fix 0.9 minus one vague deduction');
        $this->assertGreaterThanOrEqual(0.9, $o->factors['client']['value']);
    }

    public function test_genuine_off_platform_requests_still_count_and_independent_concerns_add_up_to_a_cap(): void
    {
        $c = OpportunityScorer::concerns(['Asks to move to WhatsApp', 'Wants your personal email address', 'Looks like a scam', 'Wants a free sample first']);
        $this->assertSame(['off-platform contact', 'suspicious request', 'unpaid work'], array_keys($c['client']));

        $o = $this->evaluate($this->variant('S1', scoring: ['red_flags' => ['Asks to move to WhatsApp', 'Looks like a scam', 'Wants a free sample first']]));
        $this->assertSame(0.45, $o->factors['client']['value'], '0.95 minus the 0.5 cap');
    }

    public function test_new_verified_clients_are_neutral(): void
    {
        $o = $this->evaluate($this->variant('S1', details: ['client' => ['payment_verified' => true]]));

        $this->assertSame(0.65, $o->factors['client']['value']);
        $this->assertEmpty($o->factors['client']['risks']);
    }

    public function test_early_competition_is_neutral_not_full_marks(): void
    {
        $f = $this->evaluate('R5')->factors['competition'];

        $this->assertSame(0.5, $f['value']);
        $this->assertTrue($f['unknown']);
        $this->assertStringContainsString('too early to judge', implode(' ', $f['notes']));
    }

    public function test_profit_uses_the_lower_quarter_of_the_budget_and_no_extra_buffer(): void
    {
        $this->assertSame(75.0, OpportunityScorer::realisticBudget(50, 150));
        $this->assertSame(80.0, OpportunityScorer::realisticBudget(80, 0));

        // 50-150 range -> 75; 75 x 0.9 / 3h = 22.5/h = 0.9 of 25 -> 0.8. The midpoint (100) would give 30/h -> 1.0.
        $f = $this->evaluate($this->variant('S1', details: ['budget_min' => 50, 'budget_max' => 150], scoring: ['estimated_hours' => 3]))->factors['profit'];
        $this->assertSame(0.8, $f['value']);
        $this->assertStringContainsString('lower quarter', implode(' ', $f['good']));
    }

    #[DataProvider('classes')]
    public function test_class_labels_and_actions_are_never_win_predictions(string $class): void
    {
        foreach ([Opportunity::CLASSES[$class], Opportunity::ACTIONS[$class]] as $text) {
            $this->assertDoesNotMatchRegularExpression('/win|chance|probab|%/i', $text);
        }
    }

    public static function classes(): array
    {
        return [['A'], ['B'], ['C']];
    }
}
