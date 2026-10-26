<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Livewire\PasteProject;
use App\Livewire\ProjectShow;
use App\Livewire\SettingsPage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\PostChecker;
use App\Services\Drafting\PricingCalculator;
use App\Services\Drafting\Turnaround;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use App\Services\Telegram\BotCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fixes from testing pasted project #12 ("Conference Accommodation Management System").
 */
class DraftRulesTest extends TestCase
{
    use RefreshDatabase;

    private const BRIEF = 'We need a conference accommodation management system. Attendees book rooms, hotel staff '
        .'manage allocations, admins see reports. Needs user roles (attendee, hotel, admin), an audit log of every '
        .'change, CSV exports and email confirmations.';

    private function pasteProject12(): Project
    {
        $this->actingAs(User::factory()->create());
        $this->claude->reply($this->scoringReply([
            'fit_score' => 18, 'category' => 'new_build', 'estimated_hours' => 120,
            'reason' => 'Full custom booking app, outside the fix/maintenance focus',
        ]));

        Livewire::test(PasteProject::class)
            ->set('title', 'Conference Accommodation Management System')
            ->set('description', self::BRIEF)
            ->set('budgetMin', 250)->set('budgetMax', 750)
            ->set('bidCount', 184)->set('bidAvg', 282)
            ->set('alwaysDraft', true)
            ->call('save')
            ->assertHasNoErrors();

        return Project::query()->sole();
    }

    // --- 1. Pasted projects go through the same filters ---------------------

    public function test_a_filtered_paste_is_scored_but_not_drafted_and_shows_why(): void
    {
        $project = $this->pasteProject12();

        $this->assertCount(1, $this->claude->requests, 'Only scoring ran, no drafting.');
        $this->assertSame(0, $project->drafts()->count());
        $this->assertSame('scored', $project->ai_state);

        Livewire::test(ProjectShow::class, ['project' => $project])
            ->assertSee('Filtered: too many bids (184), new build, 184 bids (limit 100), score 0 (min 50)') // base 18, -25 for 184 bids
            ->assertSee('Draft anyway')
            ->call('draft') // the plain Draft button must not spend AI on a filtered project
            ->assertSet('projectId', $project->id);
        $this->assertCount(1, $this->claude->requests);
    }

    public function test_draft_anyway_drafts_a_filtered_paste(): void
    {
        $project = $this->pasteProject12();
        $this->claude->reply($this->draftReply(['proposal_text' => $this->project12Proposal()]), 'claude-sonnet-5-5');

        Livewire::test(ProjectShow::class, ['project' => $project])->call('draft', true);

        $this->assertSame(1, $project->drafts()->count());
        $this->assertSame('drafted', $project->fresh()->ai_state);
    }

    public function test_paste_runs_the_rules_filter_like_live_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $this->claude->reply($this->scoringReply(['fit_score' => 90]));

        Livewire::test(PasteProject::class)
            ->set('description', 'Fix my WooCommerce checkout. Also need SEO backlinks for the store pages.')
            ->set('budgetMin', 100)->set('budgetMax', 200)
            ->call('save');

        $project = Project::query()->sole();
        $this->assertSame('Excluded keyword: backlinks', $project->filter_reason);
        $this->assertSame(0, $project->drafts()->count(), 'High score but filtered: no auto-draft.');
    }

    public function test_live_new_build_with_too_many_bids_is_not_drafted_or_alerted(): void
    {
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 120]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90, 'category' => 'new_build']));

        ScoreProject::dispatchSync($project->id);

        $this->assertSame(0, $project->drafts()->count());
        $this->assertCount(1, $this->claude->requests);
    }

    // --- 3. Pricing checks the scope ------------------------------------------

    public function test_scope_far_above_the_average_bid_raises_the_bid_and_warns(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25]);

        $p = app(PricingCalculator::class)->price(
            ['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 250, 'budget_max' => 750, 'bid_count' => 184, 'bid_avg' => 282],
            120,
        );

        $this->assertSame('Budget too low for scope: estimate 120 hours = USD 3000', $p['warning']);
        $this->assertSame(750.0, $p['amount'], 'Not 268 (95% of average): raised to the budget max, since the scope is larger.');
        $this->assertStringContainsString('budget maximum', $p['reason']);
    }

    public function test_scope_within_twice_the_price_keeps_the_average_based_bid(): void
    {
        $p = app(PricingCalculator::class)->price(
            ['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 250, 'budget_max' => 750, 'bid_count' => 184, 'bid_avg' => 282],
            20, // 20h x $25 = $500 < 2 x $268
        );

        $this->assertNull($p['warning']);
        $this->assertSame(268.0, $p['amount']);
    }

    public function test_no_budget_max_bids_the_scope_and_the_floor_rule_still_applies(): void
    {
        app(Settings::class)->set(['floor_price_usd' => 60]);
        $calc = app(PricingCalculator::class);

        $scope = $calc->price(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 30, 'bid_count' => 10, 'bid_avg' => 40], 40);
        $this->assertSame(1000.0, $scope['amount']);

        $floor = $calc->price(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 10, 'budget_max' => 30, 'bid_count' => 5, 'bid_avg' => 20], 1);
        $this->assertSame(60.0, $floor['amount']);
        $this->assertNull($floor['warning']);
    }

    public function test_the_scope_warning_is_saved_on_the_draft_and_shown(): void
    {
        $project = $this->pasteProject12();
        $this->claude->reply($this->draftReply(['proposal_text' => $this->project12Proposal()]), 'claude-sonnet-5-5');

        Livewire::test(ProjectShow::class, ['project' => $project])->call('draft', true)
            ->call('$refresh')
            ->assertSee('Budget too low for scope: estimate 120 hours = USD 3000');

        $this->assertContains('Budget too low for scope: estimate 120 hours = USD 3000', $project->latestDraft()->first()->warnings);
    }

    // --- 4. Delivery days come from the turnaround settings --------------------

    public function test_turnaround_lines_are_matched_to_the_category(): void
    {
        $notes = "Fix: 1-2 days\nSpeed: 2-3 days\nCheckout: 4 days\nNew build - 3 weeks";

        $this->assertSame(2, Turnaround::daysFor($notes, 'fix'));
        $this->assertSame(3, Turnaround::daysFor($notes, 'speed'));
        $this->assertSame(4, Turnaround::daysFor($notes, 'checkout_payment'));
        $this->assertSame(21, Turnaround::daysFor($notes, 'new_build'));
        $this->assertNull(Turnaround::daysFor($notes, 'security'));
        $this->assertNull(Turnaround::daysFor('', 'fix'));
    }

    public function test_delivery_comes_from_settings_not_the_model(): void
    {
        app(Settings::class)->set(['turnaround_notes' => "Fix: 1-2 days\nSpeed: 2-3 days"]);
        $project = $this->makeProject([], [], $this->scoringReply(['category' => 'fix']));
        $this->claude->reply($this->draftReply(['delivery_days' => 28]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertSame(2, $draft->delivery_days);
        $this->assertNull($draft->warnings);
        $this->assertStringContainsString('Delivery time: 2 days.', $this->claude->requests[0]->userContent);
    }

    public function test_no_matching_turnaround_leaves_delivery_blank_and_flags_it(): void
    {
        app(Settings::class)->set(['turnaround_notes' => "Fix: 1-2 days\nSpeed: 2-3 days"]);
        $project = $this->makeProject([], [], $this->scoringReply(['category' => 'new_build']));
        $this->claude->reply($this->draftReply(['delivery_days' => 28]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertNull($draft->delivery_days);
        $this->assertContains('No turnaround set for "New build" in Settings: delivery left blank, set it yourself.', $draft->warnings);
        $this->assertStringContainsString('do not mention a timeline', $this->claude->requests[0]->userContent);
        $this->assertArrayNotHasKey('delivery_days', Drafter::schema()['properties']);
    }

    // --- 2 and 5. Invented tech and self-undermining wording -----------------

    public function test_invented_tech_and_self_undermining_wording_fail_the_checks(): void
    {
        $source = "Skills: WooCommerce\n".self::BRIEF;

        $this->assertSame(['Laravel'], PostChecker::unknownTech('I would build a standalone Laravel app for this.', $source));
        $this->assertSame([], PostChecker::unknownTech('I would extend your WooCommerce setup.', $source));
        $this->assertSame([], PostChecker::unknownTech('I can react quickly to changes.', $source));

        $this->assertNotNull(PostChecker::underminingPhrase('My background is WordPress and WooCommerce fixes and integrations, so this is a new build for me.'));
        $this->assertNotNull(PostChecker::underminingPhrase("I haven't built a booking system before."));
        $this->assertNull(PostChecker::underminingPhrase('I would build the booking flow first, then the admin reports.'));
    }

    public function test_drafting_prompt_carries_the_tech_and_tone_rules(): void
    {
        $project = $this->makeProject([], [], $this->scoringReply());
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');

        app(Drafter::class)->draft($project);

        $rules = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('Only propose a framework, CMS, programming language', $rules);
        $this->assertStringContainsString('Never offer extras that are not in the fact bank', $rules);
        $this->assertStringContainsString("Never write anything that puts down the freelancer's experience", $rules);
    }

    // --- 6. Nothing turns polling on except ticking the box -------------------

    public function test_polling_is_only_switched_on_by_a_real_tick_and_the_change_is_logged(): void
    {
        config(['bidradar.freelancer.polling_enabled' => true]);
        $this->actingAs(User::factory()->create());
        app(Settings::class)->set(['polling_enabled' => false]);

        // A non-boolean value (what a script firing a CustomEvent on the checkbox sends) is ignored.
        Livewire::test(SettingsPage::class)->set('form.polling_enabled', 'on')->call('save')->assertHasNoErrors();
        $this->assertFalse(Setting::query()->where('key', 'polling_enabled')->value('value'));

        // Saving other fields leaves it off.
        Livewire::test(SettingsPage::class)->set('form.word_min', 95)->call('save');
        $this->assertFalse(Setting::query()->where('key', 'polling_enabled')->value('value'));

        // A real tick turns it on, and the change is logged.
        Log::spy();
        Livewire::test(SettingsPage::class)->set('form.polling_enabled', true)->call('save');
        $this->assertTrue(Setting::query()->where('key', 'polling_enabled')->value('value'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'polling_enabled changed on the Settings page' && $ctx['to'] === true)->once();
    }

    public function test_telegram_commands_seeder_and_defaults_never_turn_polling_on(): void
    {
        $this->assertFalse(config('bidradar.defaults.polling_enabled'));

        $this->seed();
        $this->assertNull(Setting::query()->where('key', 'polling_enabled')->value('value'));

        $bot = app(BotCommands::class);
        foreach (['/resume', '/pause', '/resume', '/today', '/start'] as $command) {
            $bot->handle($command);
        }
        $this->assertNull(Setting::query()->where('key', 'polling_enabled')->value('value'));
        $this->assertFalse(app(Settings::class)->get('polling_enabled'));
    }

    // --- Follow-up from re-running #12 -----------------------------------------

    public function test_a_stack_the_client_did_not_ask_for_is_flagged_even_if_it_is_in_the_fact_bank(): void
    {
        $brief = 'Skills: PHP, MySQL'."\n".self::BRIEF;

        $this->assertSame(['WordPress'], PostChecker::proposedStack(
            'I would build this as a self-hosted WordPress plugin with its own database tables.', $brief,
        ));
        // Past experience from the fact bank is fine; so is a stack the client named.
        $this->assertSame([], PostChecker::proposedStack("I've fixed WooCommerce checkouts for other stores.", $brief));
        $this->assertSame([], PostChecker::proposedStack('I would build it in PHP with a MySQL database.', $brief));
    }

    public function test_spelled_out_amounts_and_invented_extras_are_caught(): void
    {
        $line = 'Two weeks of post-launch bug fixing is included.';

        $this->assertSame(['two weeks'], PostChecker::unknownSpelledAmounts($line, self::BRIEF, ['bid_amount' => 300]));
        $this->assertNotNull(PostChecker::inventedExtra($line, '<fact_bank>Positioning: I fix WooCommerce stores.</fact_bank>'));
        $this->assertNotNull(PostChecker::inventedExtra('Free support for a month after launch.', '<fact_bank></fact_bank>'));

        // Allowed: offered in the fact bank, normal scope wording, words that are not amounts.
        $this->assertNull(PostChecker::inventedExtra($line, '<fact_bank>Every project includes 2 weeks of bug fixing after launch.</fact_bank>'));
        $this->assertNull(PostChecker::inventedExtra('The system includes support for multiple hotels per event.', '<fact_bank></fact_bank>'));
        $this->assertSame([], PostChecker::unknownSpelledAmounts('First I map the roles, then I ask one question.', self::BRIEF, []));
        $this->assertSame([], PostChecker::unknownSpelledAmounts('Done in two days.', 'Fix: 1-2 days', []));
    }

    public function test_the_project_12_draft_now_fails_and_is_retried(): void
    {
        app(Settings::class)->set(['services_offered' => 'WordPress and WooCommerce fixes and integrations']);
        $project = $this->makeProject(
            ['title' => 'Conference Accommodation Management System', 'description' => self::BRIEF],
            ['skills' => ['PHP', 'MySQL'], 'bid_count' => 184, 'bid_avg' => 282, 'budget_min' => 250, 'budget_max' => 300],
            $this->scoringReply(['category' => 'new_build', 'fit_score' => 18, 'estimated_hours' => 220]),
        );
        $bad = $this->project12Proposal().' I would build this as a self-hosted WordPress plugin. Two weeks of post-launch bug fixing is included.';
        $this->claude
            ->reply($this->draftReply(['proposal_text' => $bad]), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => $this->project12Proposal()]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $retry = $this->claude->requests[1]->userContent;
        $this->assertStringContainsString('Proposes a stack the client did not ask for: WordPress', $retry);
        $this->assertStringContainsString('Offers an extra that is not in the fact bank', $retry);
        $this->assertStringContainsString('two weeks', $retry);
        $this->assertNull($draft->check_failures);
        $this->assertSame(300.0, (float) $draft->bid_amount);
    }

    // --- Pasted skills ----------------------------------------------------------

    public function test_pasted_skills_are_split_and_a_space_separated_line_still_matches(): void
    {
        $this->actingAs(User::factory()->create());
        $paste = function (string $skills): Project {
            $this->claude->reply($this->scoringReply());
            Livewire::test(PasteProject::class)
                ->set('description', 'Fix my WooCommerce checkout, it spins forever after the last update.')
                ->set('budgetMin', 100)->set('budgetMax', 200)
                ->set('skills', $skills)
                ->call('save');

            return Project::query()->latest('id')->first();
        };

        $this->assertSame(['WooCommerce', 'PHP', 'MySQL'], $paste("WooCommerce, PHP\nMySQL")->detail('skills'));

        $line = $paste('PHP Joomla WordPress MySQL HTML Software Development Laravel Web Development');
        $this->assertSame(['PHP Joomla WordPress MySQL HTML Software Development Laravel Web Development'], $line->detail('skills'));
        $this->assertNull($line->filter_reason, 'Not "No target skill": PHP, WordPress and MySQL are in the line.');

        $this->assertSame('No target skill', $paste('Logo Design, Illustration')->filter_reason);
    }

    // --- Unverified payment: a warning, not a skip ----------------------------

    public function test_unverified_client_is_scored_and_shown_but_not_drafted(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 60]));

        ScoreProject::dispatchSync($project->id);

        $project->refresh();
        $this->assertSame('scored', $project->ai_state);
        $this->assertSame(0, $project->drafts()->count());
        $this->get('/')->assertSee($project->title)->assertSee('payment not verified');
        Livewire::test(ProjectShow::class, ['project' => $project])
            ->assertSee('Filtered: client payment not verified')
            ->assertSee('Draft anyway');
    }

    public function test_high_fit_unverified_live_project_is_alerted_without_a_draft(): void
    {
        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $project = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false]]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90]));

        ScoreProject::dispatchSync($project->id);

        $this->assertSame(0, $project->drafts()->count());
        $this->assertCount(1, $this->claude->requests, 'Scored only; no drafting AI call.');
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Not drafted: client payment not verified'));
    }

    public function test_high_fit_unverified_new_build_with_too_many_bids_is_not_alerted(): void
    {
        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        Http::fake();
        $project = $this->makeProject(['source' => 'freelancer'], ['client' => ['payment_verified' => false], 'bid_count' => 120]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90, 'category' => 'new_build']));

        ScoreProject::dispatchSync($project->id);

        Http::assertNothingSent();
    }

    // --- Feedback on the "Pixel-Perfect Mockup to Website" bid ------------------

    public function test_scoring_keeps_client_requirements_and_the_draft_is_told_to_address_them(): void
    {
        $scoring = Scorer::normalize($this->scoringReply([
            'client_requirements' => ['Link to past work with a similar look and feel', ''],
        ]));
        $this->assertSame(['Link to past work with a similar look and feel'], $scoring['client_requirements']);
        $this->assertContains('client_requirements', Scorer::schema()['required']);

        $project = $this->makeProject([], [], $scoring);
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5');
        app(Drafter::class)->draft($project);

        $this->assertStringContainsString("Client requirements to address: \n  * Link to past work with a similar look and feel", $this->claude->requests[0]->userContent);
        $rules = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('never say links are', $rules);
        $this->assertStringContainsString('closest to this job in scope and type', $rules);
        $this->assertStringContainsString('never a fragment on its own line', $rules);
    }

    public function test_portfolio_notes_are_part_of_the_fact_bank_and_saved_from_settings(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->set('form.portfolio_notes', '3 WooCommerce stores built from Figma designs')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3 WooCommerce stores built from Figma designs', app(Settings::class)->get('portfolio_notes'));
        $this->assertStringContainsString("## My Freelancer profile portfolio\n3 WooCommerce stores built from Figma designs", app(Drafter::class)->factBank());
    }

    public function test_the_sent_bid_would_now_fail_on_the_links_line_and_the_stats_fragment(): void
    {
        $sent = "Links aren't allowed in bids, so here is a close example in words.\n\nRating 4.9 from 409 reviews.\n\nWill the video sections use self-hosted files or embedded players?";

        $this->assertSame("Links aren't allowed", PostChecker::linkRestrictionPhrase($sent));
        $this->assertSame('Rating 4.9 from 409 reviews.', PostChecker::statsFragment($sent));

        $this->assertNotNull(PostChecker::linkRestrictionPhrase('I cannot share links here.'));
        $this->assertNull(PostChecker::linkRestrictionPhrase('Samples are in my Freelancer portfolio, and I can share more in the Freelancer chat.'));
        $this->assertNull(PostChecker::statsFragment('I have a 4.9 rating from 409 reviews, mostly WooCommerce work.'));
        $this->assertSame([], PostChecker::contactViolations('I can share more samples here in the Freelancer chat.'));
    }

    private function project12Proposal(): string
    {
        return 'Your conference needs attendees to book rooms, hotel staff to manage allocations, and admins to see reports, '
            .'all with clear roles and an audit log of every change. I would start by mapping the three roles and what each '
            .'one can see and change, so permissions are right from the first screen. Then I would build the booking and '
            .'allocation flow with email confirmations, and log every change with who made it and when. Finally I would add '
            .'the admin reports and CSV exports, and test each role end to end before handover. Do hotels need to confirm '
            .'each allocation, or can attendees see their room as soon as they book?';
    }
}
