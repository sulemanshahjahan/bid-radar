<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\User;
use App\Services\BidFill;
use App\Services\Drafting\PricingCalculator;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bidding within seconds: the owner's range-minimum pricing (so the filled price matches the draft),
 * the "Fill bid" link that carries the draft to the Freelancer form, and the bookmarklet that fills it.
 */
class FastBiddingTest extends TestCase
{
    use RefreshDatabase;

    private function price(array $details, ?int $hours = null): array
    {
        app(Settings::class)->set(['pricing_mode' => 'range_minimum', 'min_hourly_usd' => 12]);

        return app(PricingCalculator::class)->price($details + ['exchange_rate' => 1, 'bid_count' => 30, 'bid_avg' => 400], $hours);
    }

    public function test_ranges_bid_their_minimum(): void
    {
        $this->assertSame(250.0, $this->price(['type' => 'fixed', 'budget_min' => 250, 'budget_max' => 750], 40)['amount']);
        $this->assertSame(750.0, $this->price(['type' => 'fixed', 'budget_min' => 750, 'budget_max' => 1500], 60)['amount']);
        $this->assertSame(1500.0, $this->price(['type' => 'fixed', 'budget_min' => 1500, 'budget_max' => 3000])['amount']);
    }

    public function test_small_budgets_bid_50_to_150_by_hours_and_quick_fixes_bid_40(): void
    {
        $small = ['type' => 'fixed', 'budget_min' => 30, 'budget_max' => 250];

        $this->assertSame(40.0, $this->price($small, 2)['amount']);
        $this->assertSame(110.0, $this->price($small, 14)['amount']);
        $this->assertSame(150.0, $this->price($small, 40)['amount']);
        $this->assertSame(70.0, $this->price($small, 5)['amount']);
        $this->assertSame(50.0, $this->price($small, null)['amount']);
    }

    public function test_small_bids_stay_inside_the_clients_range(): void
    {
        $this->assertSame(30.0, $this->price(['type' => 'fixed', 'budget_min' => 10, 'budget_max' => 30], 8)['amount']);
        $this->assertSame(80.0, $this->price(['type' => 'fixed', 'budget_min' => 80, 'budget_max' => 200], 2)['amount']);
    }

    public function test_other_currencies_use_the_usd_size_of_the_budget(): void
    {
        // EUR 30-250 at 1.1 USD per EUR tops out at USD 275: not "small", so it bids the range minimum.
        $result = $this->price(['type' => 'fixed', 'currency' => 'EUR', 'budget_min' => 30, 'budget_max' => 250, 'exchange_rate' => 1.1], 4);
        $this->assertSame(30.0, $result['amount']);
    }

    public function test_hourly_bids_the_range_minimum_with_the_floor(): void
    {
        $this->assertSame(15.0, $this->price(['type' => 'hourly', 'budget_min' => 15, 'budget_max' => 25])['amount']);
        $this->assertSame(12.0, $this->price(['type' => 'hourly', 'budget_min' => 8, 'budget_max' => 15])['amount']);
    }

    private function projectWithDraft(array $draft = []): \App\Models\Project
    {
        $project = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/php/Fix-My-Site'],
            ['type' => 'fixed'], $this->scoringReply(['estimated_hours' => 6]));
        Draft::query()->create(array_merge([
            'project_id' => $project->id, 'version' => 1, 'proposal_text' => "Hi,\n\nThe risk is the café's checkout.\n\nBudget: \$120.",
            'bid_amount' => 120, 'currency' => 'USD', 'is_hourly' => false, 'delivery_days' => 4,
        ], $draft));

        return $project->fresh();
    }

    public function test_the_fill_link_opens_the_bid_form_with_the_draft_in_the_fragment(): void
    {
        $project = $this->projectWithDraft();
        $link = BidFill::link($project);

        $this->assertStringStartsWith('https://www.freelancer.com/projects/php/Fix-My-Site/details#bidradar=', $link);
        $encoded = substr($link, strpos($link, '=') + 1);
        $data = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);
        $this->assertSame(120, (int) $data['a']);
        $this->assertSame(4, $data['d']);
        $this->assertSame(6, $data['hrs']);
        $this->assertFalse($data['h']);
        $this->assertStringContainsString("café's checkout", $data['t']);
        $this->assertSame('/projects/php/Fix-My-Site', $data['u']);
    }

    public function test_fill_bid_also_copies_the_bid_for_the_bookmarklet(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->projectWithDraft();

        $this->assertStringStartsWith('BIDRADAR:', BidFill::clipboardText($project));
        $this->get(route('projects.show', $project))->assertOk()->assertSee('data-fill-clip="BIDRADAR:', false);
    }

    public function test_an_edited_proposal_is_the_one_filled(): void
    {
        $project = $this->projectWithDraft(['edited_text' => 'My own words. Budget: $120.']);
        $encoded = substr(BidFill::link($project), strpos(BidFill::link($project), '=') + 1);

        $this->assertSame('My own words. Budget: $120.', json_decode(base64_decode(strtr($encoded, '-_', '+/')), true)['t']);
    }

    public function test_no_fill_link_without_a_draft(): void
    {
        $project = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/php/X']);

        $this->assertNull(BidFill::link($project));
    }

    public function test_the_project_page_shows_fill_bid_and_settings_offer_the_bookmarklet(): void
    {
        $this->actingAs(User::factory()->create());
        $project = $this->projectWithDraft();

        $this->get(route('projects.show', $project))->assertOk()->assertSee('Fill bid')->assertSee('#bidradar=', false);
        $this->get(route('settings'))->assertOk()->assertSee('BidRadar Fill')->assertSee('javascript:', false);
    }

    public function test_the_bookmarklet_never_presses_place_bid(): void
    {
        $js = rawurldecode(substr(BidFill::bookmarklet(), strlen('javascript:')));

        $this->assertStringContainsString('placeholder="Enter bid amount"', $js);
        $this->assertStringNotContainsString('btn.click()', $js);
        $this->assertStringContainsString('scrollIntoView', $js);
    }

    public function test_a_proposal_over_freelancers_1500_character_limit_fails_the_checks(): void
    {
        $long = str_repeat('Your checkout loses buyers on mobile because the payment step reloads. ', 25);
        $failures = app(\App\Services\Drafting\PostChecker::class)->check(['proposal_text' => $long], null, $long);

        $this->assertNotEmpty(array_filter($failures, fn ($f) => str_contains($f, 'Freelancer accepts at most 1480')));
    }

    public function test_the_bookmarklet_trims_to_1500_characters_and_caps_weekly_hours(): void
    {
        $js = rawurldecode(substr(BidFill::bookmarklet(), strlen('javascript:')));

        $this->assertStringContainsString('text.length > 1500', $js);
        $this->assertStringContainsString('Math.min(p.hrs || 10, 20)', $js);
    }
}
