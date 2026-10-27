<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Livewire\StatsPage;
use App\Models\ApiCall;
use App\Models\Draft;
use App\Models\Project;
use App\Models\User;
use App\Services\Drafting\ClientInstructions;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\PostChecker;
use App\Services\Drafting\PricingCalculator;
use App\Services\Drafting\ProposalLength;
use App\Services\Drafting\ProposalQuality;
use App\Services\Drafting\Turnaround;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt v2 (hooks, decisive recommendations, sized delivery and length, literal
 * client instructions, generic-language gates). Built from the owner's case
 * studies A-C and the scenarios in tests/fixtures/proposals/briefs.php.
 * These test the code's decisions and checks; the model's writing is judged
 * with `php artisan bidradar:try-drafts`.
 */
class ProposalStrategyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> */
    private static function briefs(): array
    {
        return require base_path('tests/fixtures/proposals/briefs.php');
    }

    private function briefProject(string $key, array $scoring = []): Project
    {
        $b = self::briefs()[$key];

        return $this->makeProject(
            ['title' => $b['title'], 'description' => $b['description']],
            $b['details'],
            $this->scoringReply($scoring),
        );
    }

    // --- Delivery sized to the job -------------------------------------------

    public function test_with_an_estimate_the_turnaround_range_is_only_advisory(): void
    {
        $notes = "Fix: 1-5 days\nOther: 3-5 days\nCheckout: 4 days\nNew build: 4-8 weeks";
        $days = fn (string $cat, ?int $hours, array $deps = []) => Turnaround::plan($notes, $cat, $hours, $deps)['days'] ?? null;
        $membershipDeps = ['Lemon Squeezy account and API access', 'ARMember licence', 'Course videos from the client'];

        // The two v2 outputs the owner flagged.
        $this->assertSame(1, $days('other', 4), 'HTML5 cards, 4 hours: not 3 days because Other starts at 3.');
        $this->assertSame(11, $days('new_build', 30, $membershipDeps), 'Membership, 30 hours: 7 working days + 2 for dependencies = 9, plus a weekend. Not 28.');

        $this->assertSame(2, $days('fix', 8));
        $this->assertSame(17, $days('fix', 60), 'Past the range when the work needs it: realistic, and flagged.');
        $this->assertSame('above', Turnaround::plan($notes, 'fix', 60)['outside_range']);
        $this->assertSame('below', Turnaround::plan($notes, 'new_build', 30, $membershipDeps)['outside_range']);
        $this->assertSame(5, $days('fix', null), 'No estimate: the top of the range, as before.');
        $this->assertSame(1, $days('fix', 3, ['Stripe API keys', 'Plugin licence']), 'A one-day job waits on nobody.');
        $this->assertNull($days('security', 3), 'No line for the category: no guess.');

        $slower = Turnaround::plan($notes, 'fix', 12, [], 3);
        $this->assertSame([5, 7], [$slower['working_days'], $slower['days']], 'Fewer hours a day for this project: more days.');
    }

    public function test_working_days_become_calendar_days(): void
    {
        $this->assertSame([1, 4, 7, 8, 14, 15], array_map(Turnaround::calendarDays(...), [1, 4, 5, 6, 10, 11]));
    }

    public function test_the_card_section_gets_a_short_delivery_and_a_short_target(): void
    {
        app(Settings::class)->set(['turnaround_notes' => 'Fix: 3-5 days']);
        $project = $this->briefProject('B', ['client_test_phrase' => 'I Got This', 'estimated_hours' => 4]);
        $this->claude->reply($this->draftReply(['proposal_text' => $this->cardProposal()]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $content = $this->claude->requests[0]->userContent;
        $this->assertSame(1, $draft->delivery_days, 'v1 said 5 days here, v2 said 3.');
        $this->assertStringContainsString('Delivery time: 1 day. Calendar days, set by code: about 4 hours of work is 1 working day', $content);
        $this->assertStringContainsString('Delivery advantage: 1 day for a client in a hurry', $content);
        $this->assertStringContainsString('Job size: small. Word target: 80-130 words', $content);
        $this->assertStringContainsString('Required opening: the client told bidders to begin with "I Got This"', $content);
        $this->assertContains('Delivery of 1 day from the 4-hour estimate is below your usual 3-5 days for "Fix": check it before bidding.', $draft->warnings);
        $this->assertNull($draft->check_failures);
    }

    // --- Client instructions, checked literally -------------------------------

    public function test_required_openings_and_word_limits_are_read_from_the_brief(): void
    {
        $b = self::briefs();

        $this->assertTrue(ClientInstructions::phraseMustOpen($b['B']['description'], 'I Got This'));
        $this->assertTrue(ClientInstructions::phraseMustOpen($b['H']['description'], 'Ready Today'));
        $this->assertTrue(ClientInstructions::phraseMustOpen('Start your bid with the word BLUE.', 'BLUE'));
        $this->assertFalse(ClientInstructions::phraseMustOpen('Include the word BLUE somewhere in your bid.', 'BLUE'));
        $this->assertFalse(ClientInstructions::phraseMustOpen($b['B']['description'], null));

        $this->assertSame(90, ClientInstructions::wordLimit($b['H']['description']));
        $this->assertSame(150, ClientInstructions::wordLimit('Proposals: 150 words max please.'));
        $this->assertNull(ClientInstructions::wordLimit($b['A']['description']));

        $this->assertTrue(ClientInstructions::opensWith('I Got This. A grid is the better fit...', 'I Got This'));
        $this->assertTrue(ClientInstructions::opensWith('"I got this" - with seven cards...', 'I Got This.'));
        $this->assertFalse(ClientInstructions::opensWith('Hi, I Got This.', 'I Got This'));
        $this->assertFalse(ClientInstructions::opensWith('I Got Thistle', 'I Got This'));
    }

    public function test_every_scenario_brief_gets_the_right_hard_instructions(): void
    {
        $phrases = ['B' => 'I Got This', 'H' => 'Ready Today'];

        foreach (self::briefs() as $key => $b) {
            $this->assertSame(isset($phrases[$key]), ClientInstructions::phraseMustOpen($b['description'], $phrases[$key] ?? 'BLUE'), "Brief {$key}");
            $this->assertSame($key === 'H' ? 90 : null, ClientInstructions::wordLimit($b['description']), "Brief {$key}");
        }
    }

    public function test_the_owners_winning_card_bid_would_be_flagged_for_the_missing_opening(): void
    {
        // Case study B: the hand-written bid won, but skipped the mandatory phrase. The engine must not.
        $manual = "I've built exactly this type of section dozens of times, and I can have it live on your site before most freelancers finish reading your brief.\n\n"
            .'Hi, honest recommendation since you asked for advice: with only seven cards, a responsive grid beats a carousel in every way that matters.';

        $failures = $this->check($manual, 'I Got This', ['phrase_opens' => true]);

        $this->assertContains("Must begin with the client's required phrase \"I Got This\", before anything else.", $failures);
        $this->assertTrue(collect($failures)->contains(fn ($f) => str_contains($f, 'most freelancers')), 'Comparisons with other freelancers are flagged.');
    }

    public function test_a_client_word_limit_beats_the_settings_range(): void
    {
        $length = ProposalLength::for(2, 'fix', 80, 250, 90);
        $this->assertSame(90, $length['max']);
        $this->assertSame(54, $length['min'], 'The minimum drops so a short limit can be met.');
        $this->assertLessThanOrEqual(90, $length['target_max']);

        $long = implode(' ', array_fill(0, 95, 'word'));
        $failures = $this->check($long, null, ['word_min' => $length['min'], 'word_max' => $length['max'], 'client_word_limit' => 90]);
        $this->assertContains('Word count is 95; the client asked for at most 90 words.', $failures);
    }

    public function test_length_follows_the_size_of_the_job(): void
    {
        $this->assertSame('quick', ProposalLength::for(3, 'other', 80, 250)['size']);
        $this->assertSame('small', ProposalLength::for(5, 'other', 80, 250)['size']);
        $this->assertSame([120, 180], array_values(array_intersect_key(ProposalLength::for(20, 'fix', 80, 250), array_flip(['target_min', 'target_max']))));
        $complex = ProposalLength::for(120, 'new_build', 80, 250);
        $this->assertSame(['complex', 160, 250, 250], [$complex['size'], $complex['target_min'], $complex['target_max'], $complex['max']]);
        $this->assertSame(163, ProposalLength::for(5, 'fix', 80, 250)['max'], 'A small fix fails the check well before 250 words.');
        $quick = ProposalLength::for(2, 'fix', 80, 250);
        $this->assertSame([60, 100, 60, 125], [$quick['target_min'], $quick['target_max'], $quick['min'], $quick['max']], 'A quick fix may be shorter than the Settings minimum.');
        $this->assertSame(150, ProposalLength::for(120, 'new_build', 80, 150)['max'], 'Never past the owner\'s own maximum.');
        $this->assertSame('small', ProposalLength::for(null, 'fix', 80, 250)['size']);
        $this->assertSame('medium', ProposalLength::for(null, 'new_build', 80, 250)['size']);
    }

    // --- Honesty gates: the corrections in the case studies -----------------

    public function test_invented_statistics_guarantees_and_unverified_availability_fail(): void
    {
        // Case study A's opening statistic.
        $this->assertNotNull(PostChecker::unsupportedClaim('Most membership sites fail within six months because the payment flow leaks customers.'));
        $this->assertNull(PostChecker::unsupportedClaim('If checkout and ARMember do not talk to each other, customers can pay and still be locked out.'));

        $this->assertNotNull(PostChecker::overpromise('I already know exactly what is causing your issue and can guarantee a fix.'));
        $this->assertNull(PostChecker::overpromise("I'd check whether it comes from stale Elementor CSS or a theme override."));
        $this->assertNull(PostChecker::overpromise('Every fix comes with my guarantee.', '<fact_bank>Money-back guarantee on fixes.</fact_bank>'));

        $this->assertSame('start today', PostChecker::unverifiedAvailability('I can start today and deliver within 5 days.', '<fact_bank></fact_bank>'));
        $this->assertNull(PostChecker::unverifiedAvailability('I can start today.', "<fact_bank>## Availability\nUsually free to start the same day.</fact_bank>"));
        $this->assertNull(PostChecker::unverifiedAvailability('Share the files and I can begin.', '<fact_bank></fact_bank>'));
    }

    public function test_a_delivery_in_the_text_must_match_the_bid(): void
    {
        $this->assertSame('deliver within 5 days', PostChecker::conflictingDelivery('I can start today and deliver within 5 days.', 1));
        $this->assertNull(PostChecker::conflictingDelivery('Delivery in 1 day.', 1));
        $this->assertNull(PostChecker::conflictingDelivery('I fixed this for another client and finished it in 2 days.', 1), 'Past work is not a delivery promise.');
        $this->assertNotNull(PostChecker::conflictingDelivery('Ready in 3 days.', null), 'No delivery time given: none may be stated.');
    }

    public function test_generic_language_and_step_lists_fail(): void
    {
        // Case study C's generic bid.
        $generic = 'As an accomplished Elementor developer, with experience fixing CSS discrepancies, I have the fundamental skills necessary to address your project needs effectively.';
        $failures = PostChecker::genericLanguage($generic);
        $this->assertTrue(collect($failures)->contains(fn ($f) => str_contains($f, 'fundamental skills')));
        $this->assertTrue(collect($failures)->contains(fn ($f) => str_starts_with($f, 'Generic opening')));

        $this->assertNotEmpty(PostChecker::genericLanguage('I understand that you need a faster site. I will fix it.'));
        $this->assertNotEmpty(PostChecker::genericLanguage('Hi, I can help you with this.'));
        $this->assertSame([], PostChecker::genericLanguage("When Elementor looks right in the editor but breaks live, it's usually stale generated CSS, caching or a theme override."));
        $this->assertSame([], PostChecker::genericLanguage('I Got This. For seven cards I would use CSS Grid rather than a carousel.', 'I Got This'));
        $this->assertNotEmpty(PostChecker::genericLanguage('I Got This. I can help you with your cards.', 'I Got This'), 'The opener after the required phrase is checked too.');

        // Case study B's engine-written alternative.
        $this->assertTrue(PostChecker::stepList('First I will review your files. Then I will build the grid. Finally I will send commented HTML.'));
        $this->assertFalse(PostChecker::stepList("I'll trace the conflict, fix the styles and check desktop and mobile. Then you get before/after screenshots."));
    }

    // --- Plan, self-review and the one revision -------------------------------

    public function test_the_plan_is_saved_with_the_prompt_version(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertSame(Drafter::PROMPT_VERSION, $draft->prompt_version);
        $this->assertSame('diagnosis', $draft->strategy['hook_type']);
        $this->assertCount(3, $draft->strategy['hook_candidates']);
        $this->assertSame(77, $draft->strategy['quality']); // 8x20% + 8x15% + 8x15% + 6x10% + 8x10% + 7x10% + 8x10% + 8x10%
        $this->assertSame('small', $draft->strategy['size']);
        $this->assertNull($draft->strategy['revised']);
        $this->assertSame(1, $draft->attempts);

        $schema = Drafter::schema();
        $this->assertSame(['plan', 'proposal_text', 'self_review'], array_slice(array_keys($schema['properties']), 0, 3), 'Plan comes before the text.');
        $this->assertSame(array_keys(ProposalQuality::HOOKS), $schema['properties']['plan']['properties']['hook_type']['enum']);
    }

    public function test_a_weak_self_review_gets_one_revision(): void
    {
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 7);
        $weak['hook'] = 2;
        $weak['concision'] = 3;
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude
            ->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5')
            ->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertCount(2, $this->claude->requests);
        $retry = $this->claude->requests[1]->userContent;
        $this->assertStringContainsString('passed the checks but you rated it weak on', $retry);
        $this->assertStringContainsString('Opening hook', $retry);
        $this->assertSame('quality', $draft->strategy['revised']);
        $this->assertSame(2, $draft->attempts);
    }

    public function test_a_revision_that_breaks_a_rule_keeps_the_earlier_draft(): void
    {
        $weak = array_fill_keys(array_keys(ProposalQuality::CRITERIA), 3);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude
            ->reply($this->draftReply(['self_review' => $weak]), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => 'I guarantee it. Email me at a@b.com.']), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertSame($this->draftReply()['proposal_text'], $draft->proposal_text);
        $this->assertNull($draft->check_failures);
        $this->assertSame('quality_rejected', $draft->strategy['revised']);
    }

    public function test_a_reply_without_a_plan_is_still_saved(): void
    {
        $reply = $this->draftReply();
        unset($reply['plan'], $reply['self_review']);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($reply, 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertNull($draft->strategy['hook_type']);
        $this->assertNull($draft->strategy['quality']);
        $this->assertCount(1, $this->claude->requests, 'No self-review: no revision.');
    }

    public function test_a_missing_required_opening_is_retried_with_the_reason(): void
    {
        app(Settings::class)->set(['turnaround_notes' => 'Fix: 1-5 days']);
        $project = $this->briefProject('B', ['client_test_phrase' => 'I Got This', 'estimated_hours' => 3]);
        $wrongPlace = 'For seven cards, '.lcfirst(substr($this->cardProposal(), strlen('I Got This. ')));
        $this->claude
            ->reply($this->draftReply(['proposal_text' => $wrongPlace.' I Got This.']), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => $this->cardProposal()]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertStringContainsString("Must begin with the client's required phrase", $this->claude->requests[1]->userContent);
        $this->assertStringStartsWith('I Got This.', $draft->proposal_text);
        $this->assertNull($draft->check_failures);
    }

    public function test_recent_openings_are_shown_so_openings_vary(): void
    {
        $other = $this->makeProject(['title' => 'Other job']);
        $other->drafts()->create(['version' => 1, 'proposal_text' => 'Your WooCommerce store is losing orders at checkout. More text.', 'bid_amount' => 50]);
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        app(Drafter::class)->draft($project);

        $content = $this->claude->requests[0]->userContent;
        $this->assertStringContainsString('Do not reuse their first words or structure', $content);
        $this->assertStringContainsString('- Your WooCommerce store is losing orders at checkout.', $content);
        $this->assertStringNotContainsString('More text', $content);
    }

    public function test_the_prompt_asks_for_hooks_recommendations_and_honest_timing(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        app(Drafter::class)->draft($project);

        $rules = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('three distinct openings of different types', $rules);
        $this->assertStringContainsString('Be decisive.', $rules);
        $this->assertStringContainsString('"First... Then... Finally..." sequence', $rules);
        $this->assertStringContainsString("the fact bank's availability section says so", $rules);
        $this->assertStringContainsString('never promise both', $rules);
        $this->assertStringNotContainsString('End with one smart question', $rules);
        $this->assertStringNotContainsString('(first, then, finally)', $rules);
        $this->assertStringNotContainsString('404', $rules, 'No review counts are hardcoded: stats come from the fact bank.');
    }

    // --- Settings and stats ---------------------------------------------------

    public function test_availability_notes_are_saved_and_reach_the_fact_bank(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->set('form.availability_notes', 'Usually free to start the same day on small fixes.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString("## Availability (claim nothing beyond this)\nUsually free to start the same day on small fixes.", app(Drafter::class)->factBank());
    }

    public function test_stats_show_rates_by_opening_hook(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['diagnosis' => 'won', 'recommendation' => 'lost'] as $hook => $status) {
            $p = $this->makeProject(['status' => $status, 'bid_placed_at' => now(), 'title' => $hook]);
            $p->drafts()->create(['version' => 1, 'proposal_text' => 'x', 'bid_amount' => 50, 'strategy' => ['hook_type' => $hook]]);
        }
        $old = $this->makeProject(['status' => 'won', 'bid_placed_at' => now()]);
        $old->drafts()->create(['version' => 1, 'proposal_text' => 'x', 'bid_amount' => 50]);

        Livewire::test(StatsPage::class)
            ->assertSee('By opening of the draft')
            ->assertSee('Problem diagnosis')
            ->assertSee('Expert recommendation');
        $this->assertSame(3, Draft::query()->count());
    }

    public function test_try_drafts_prints_the_proposal_and_keeps_only_the_cost_log(): void
    {
        config(['bidradar.claude.api_key' => 'test-key']);
        $this->claude
            ->reply($this->scoringReply(['category' => 'fix', 'estimated_hours' => 2]))
            ->reply($this->draftReply(['proposal_text' => $this->elementorProposal()]), 'claude-sonnet-5-5');

        $this->artisan('bidradar:try-drafts', ['--only' => 'C'])
            ->expectsOutputToContain('[C] Elementor editor vs live styling')
            ->expectsOutputToContain('hook: diagnosis')
            ->expectsOutputToContain('stale generated CSS')
            ->assertSuccessful();

        $this->assertSame(0, Project::query()->count(), 'Nothing saved.');
        $this->assertSame(0, Draft::query()->count());
        $this->assertSame(2, ApiCall::query()->whereNull('project_id')->count(), 'The spend still counts toward the cap.');
    }

    // --- v3: scope coverage, safe commitments, urgency, pricing -----------------

    /** The v2 membership draft the owner reviewed (brief A, real output). */
    private const V2_MEMBERSHIP = "Lemon Squeezy takes payment on its own checkout, so ARMember won't know who bought the course unless something tells it. That handoff is the part to get right. A webhook from Lemon Squeezy creates or activates the ARMember account on purchase and removes access on refund. This needs a small piece of custom PHP, not a plugin setting.\n\n"
        ."For the partner program, I'd use Lemon Squeezy's built-in affiliates rather than a separate plugin. Commissions are tracked where the sale happens, so there's nothing to sync.\n\n"
        .'In 28 days you get the homepage, the FAQ and terms pages, and the gated course page with embedded videos and the PDF. The purchase-to-access flow is tested end to end.';

    public function test_a_requested_deliverable_left_out_of_the_proposal_is_caught(): void
    {
        $deliverables = ['Homepage with videos and testimonials', 'FAQ and terms pages', 'Lemon Squeezy checkout', 'Partner program page', 'Sign up / login page', 'Members-only course page with PDF'];

        $this->assertSame(['Sign up / login page'], PostChecker::missingDeliverables(self::V2_MEMBERSHIP, $deliverables));
        $this->assertSame([], PostChecker::missingDeliverables(self::V2_MEMBERSHIP.' Members register and log in on their own page.', $deliverables), 'Different words for the same page are fine.');
        $this->assertSame([], PostChecker::missingDeliverables('Anything.', []), 'Old projects without deliverables are not checked.');
    }

    public function test_missing_deliverables_send_the_draft_back_with_the_list(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply(['deliverables' => ['Stripe checkout fix', 'Abandoned cart reminders']]));
        $fixed = $this->draftReply()['proposal_text'].' Abandoned cart reminders get tested too.';
        $this->claude
            ->reply($this->draftReply(), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => $fixed]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertStringContainsString('Major deliverables (the proposal must cover every one', $this->claude->requests[0]->userContent);
        $this->assertStringContainsString('Does not mention these requested deliverables: Abandoned cart reminders.', $this->claude->requests[1]->userContent);
        $this->assertNull($draft->check_failures);
    }

    public function test_scoring_keeps_deliverables_and_dependencies_and_they_lengthen_delivery(): void
    {
        $scoring = Scorer::normalize($this->scoringReply([
            'deliverables' => ['Homepage', '', 'Login page'],
            'dependencies' => ['Lemon Squeezy account access', 'Course videos from the client', 'ARMember licence'],
            'estimated_hours' => 30,
            'category' => 'new_build',
        ]));
        $this->assertSame(['Homepage', 'Login page'], $scoring['deliverables']);
        $this->assertContains('dependencies', Scorer::schema()['required']);

        app(Settings::class)->set(['turnaround_notes' => 'New build: 4-8 weeks']);
        $project = $this->makeProject([], [], $scoring);
        $this->claude->reply($this->draftReply(['proposal_text' => $this->draftReply()['proposal_text'].' The homepage and login page come first.']), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertSame(11, $draft->delivery_days, 'v2 said 28.');
        $content = $this->claude->requests[0]->userContent;
        $this->assertStringContainsString("Depends on (outside the freelancer's control", $content);
        $this->assertStringContainsString('9 working days including 2 for the dependencies listed above', $content);
        $this->assertStringNotContainsString('Delivery advantage', $content, 'An 11-day build is not sold on speed.');
    }

    public function test_asking_for_passwords_in_chat_fails(): void
    {
        $this->assertNotNull(PostChecker::credentialRequest('Please send me your admin password and I will start.'));
        $this->assertNotNull(PostChecker::credentialRequest('Share the wp-admin login details here.'));
        $this->assertNull(PostChecker::credentialRequest('The login page needs to send members straight to the course.'));
        $this->assertNull(PostChecker::credentialRequest('A staging copy or a temporary user you can remove afterwards is all I need.'));
    }

    public function test_an_urgent_css_fix_is_quick_and_leads_with_the_delivery(): void
    {
        $b = self::briefs()['F'];
        $this->assertTrue(ClientInstructions::urgent($b['description']));
        $this->assertFalse(ClientInstructions::urgent(self::briefs()['G']['description']));

        app(Settings::class)->set(['turnaround_notes' => 'Fix: 1-2 days']);
        $project = $this->briefProject('F', ['estimated_hours' => 1]);
        $text = 'An overlapping menu button and logo since yesterday usually means a header width or flex rule changed, often after an update. '
            .'I would fix the header CSS for the mobile breakpoints only, so the desktop header stays as it is, and check it on real phone sizes. '
            .'Delivery in 1 day. Send the page link in the Freelancer chat and I can begin.';
        $this->claude->reply($this->draftReply(['proposal_text' => $text]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $content = $this->claude->requests[0]->userContent;
        $this->assertStringContainsString('Job size: quick. Word target: 60-100 words', $content);
        $this->assertStringContainsString('Delivery advantage: 1 day for a client in a hurry', $content);
        $this->assertNull($draft->check_failures, implode(' ', $draft->check_failures ?? []));
    }

    public function test_the_price_reason_shows_take_home_after_the_fee_without_moving_the_bid(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25, 'platform_fee_percent' => 10]);

        $p = app(PricingCalculator::class)->price(
            ['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 30, 'budget_max' => 250, 'bid_count' => 25, 'bid_avg' => 90],
            4,
        );

        $this->assertSame(86.0, $p['amount'], 'Still 95% of the average bid.');
        $this->assertStringEndsWith('; take-home USD 77 after the 10% fee, about USD 19/hour for 4 hours', $p['reason']);
    }

    public function test_the_prompt_covers_scope_safe_commitments_and_authority(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $rules = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('pair one specific insight with one real reason to trust it', $rules);
        $this->assertStringContainsString('never claim a number of similar jobs the fact bank does not state', $rules);
        $this->assertStringContainsString('Cover the whole brief.', $rules);
        $this->assertStringContainsString('Never ask the client to send passwords', $rules);
        $this->assertStringContainsString('duplicate or late webhook deliveries', $rules);
        $this->assertStringContainsString('state the', $rules);
        $this->assertSame('v3-coverage', $draft->prompt_version);
    }

    public function test_delivery_hours_and_fee_are_saved_from_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->set('form.delivery_hours_per_day', 4)
            ->set('form.platform_fee_percent', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(4.0, app(Settings::class)->float('delivery_hours_per_day'));
    }

    // --- Helpers ----------------------------------------------------------------

    /** @param array<string, mixed> $rules @return list<string> */
    private function check(string $text, ?string $phrase = null, array $rules = []): array
    {
        $draft = array_merge($this->draftReply(['proposal_text' => $text]), ['is_hourly' => false, 'bid_amount' => 171, 'delivery_days' => 1]);

        return app(PostChecker::class)->check($draft, $phrase, 'seven cards', 0, null, '<fact_bank></fact_bank>', $rules);
    }

    private function elementorProposal(): string
    {
        return 'When an Elementor page looks right in the editor but different live, the usual causes are stale generated CSS, '
            .'a caching or minify plugin serving old styles, or theme styles overriding Elementor. I would check those before '
            .'touching any CSS, so the fix is the real cause rather than a patch on top. I compare the editor and the live page, '
            .'correct the setting or the conflicting styles, keep every breakpoint responsive, and test in the main browsers and on mobile. '
            .'You get before/after screenshots and a short note on what was wrong. Is the difference on every device, or only on mobile?';
    }

    private function cardProposal(): string
    {
        return 'I Got This. For seven cards I would use a responsive CSS Grid rather than a carousel: there is no slider script to load, '
            .'every card is visible on desktop without arrows, and on mobile the grid simply stacks. A carousel only earns its place when '
            .'space is tight or the cards need to rotate, which is not the case here. You get semantic HTML, a small block of CSS with comments '
            .'showing where each card lives, and lazy-loaded images, so editing a card later is a two-line change. '
            .'Delivery in 1 day. Send the images and text for the cards and I can begin.';
    }
}
