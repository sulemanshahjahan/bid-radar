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
use App\Services\Settings;
use App\Services\Telegram\BotCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Filtered: too many bids (184), new build, 184 bids (limit 100), score 18 (min 50)')
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
        $this->assertSame('Excluded keyword: SEO', $project->filter_reason);
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
        $this->assertStringContainsString('Never name a framework, CMS, programming language', $rules);
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
