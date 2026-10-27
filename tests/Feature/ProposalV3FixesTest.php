<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Livewire\ProjectShow;
use App\Models\Draft;
use App\Models\Project;
use App\Models\User;
use App\Services\Drafting\Drafter;
use App\Services\Drafting\PostChecker;
use App\Services\Drafting\PricingCalculator;
use App\Services\Drafting\Turnaround;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four fixes after the v1/v2/v3 comparison (2026-10-28): Zoom as an integration,
 * dependency days only where they matter, profitability warnings, and drafts that
 * still fail a hard check flagged for manual review.
 */
class ProposalV3FixesTest extends TestCase
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

        return $this->makeProject(['title' => $b['title'], 'description' => $b['description']], $b['details'], $this->scoringReply($scoring));
    }

    // --- 1. Zoom the client asks to integrate is not off-platform contact ------

    public function test_zoom_named_by_the_client_may_be_named_as_the_integration_only(): void
    {
        $brief = self::briefs()['I']['description'];

        $this->assertSame([], PostChecker::contactViolations('I will set up the Zoom integration and connect your Zoom account through its API.', $brief));
        $this->assertSame([], PostChecker::contactViolations('Each Zoom class sits in a lesson, and Zoom registration keeps forwarded links out.', $brief));
        $this->assertSame([], PostChecker::contactViolations('I will connect LearnDash with Zoom.', $brief));

        // Still blocked: Zoom as a way to talk, even when the client uses Zoom.
        foreach (['Happy to hop on a quick Zoom call first.', 'We can discuss it on Zoom.', "Let's have a Zoom meeting with me.", 'A Zoom chat would help.'] as $text) {
            $this->assertNotEmpty(PostChecker::contactViolations($text, $brief), $text);
        }
        // And when the brief never mentions Zoom, any mention is still off-platform.
        $this->assertNotEmpty(PostChecker::contactViolations('I will set up the Zoom integration.', self::briefs()['D']['description']));
        $this->assertNotEmpty(PostChecker::contactViolations('I will set up the Zoom integration.'));
        $this->assertNotEmpty(PostChecker::contactViolations('Message me on WhatsApp.', $brief));
    }

    public function test_scenario_i_names_the_zoom_integration_and_passes_every_check(): void
    {
        app(Settings::class)->set(['turnaround_notes' => 'New build: 1-6 weeks']);
        $project = $this->briefProject('I', [
            'category' => 'new_build', 'estimated_hours' => 12,
            'deliverables' => ['LearnDash courses', 'Zoom integration', 'Enrolled-only access to live classes'],
            'dependencies' => ['Zoom API credentials', 'LearnDash license', 'Course content from the client'],
        ]);
        $text = 'The weak point in this setup is the join link: if it sits in a lesson as a plain URL, a student can forward it. '
            .'I would set up your LearnDash courses and the Zoom integration, so each scheduled Zoom class sits inside a lesson only enrolled students '
            .'can open, with Zoom registration turned on so a forwarded link alone does not get anyone in. This assumes your Zoom plan allows API apps. '
            .'I would test access with an enrolled and a non-enrolled account before handover. Delivery is 7 days. Do students pay to enroll, or will you add them yourself?';
        $this->claude->reply($this->draftReply(['proposal_text' => $text]), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertNull($draft->check_failures, implode(' ', $draft->check_failures ?? []));
        $this->assertSame(1, $draft->attempts);
        $this->assertStringContainsString('name it as what you integrate', $this->claude->requests[0]->system[0]['text']);
    }

    // --- 2. Dependencies add days only when they matter ------------------------

    public function test_ordinary_inputs_are_not_dependencies(): void
    {
        $this->assertSame(
            ['Lemon Squeezy account and API access', 'ARMember licence', 'Zoom API credentials', 'Client approval of designs'],
            Turnaround::meaningfulDependencies([
                'WordPress admin access', 'Hosting account access', 'Lemon Squeezy account and API access', 'ARMember licence',
                'Course videos and PDF from the client', 'Size chart content', 'Zoom API credentials', 'Client approval of designs',
                'Theme access', 'Access to the live site', 'Stripe and PayPal dashboard access',
            ]),
        );
    }

    public function test_small_fixes_d_and_e_are_not_stretched_by_dependencies(): void
    {
        $notes = "Checkout: 1-4 days\nFix: 1-5 days";

        // v3 gave both 4 days; 5-6 hours is 2 working days.
        $d = Turnaround::plan($notes, 'checkout_payment', 5, ['WordPress admin access', 'Stripe and PayPal dashboard access', 'WooCommerce logs']);
        $e = Turnaround::plan($notes, 'fix', 6, ['Shopify store access', 'Size chart content', 'Theme app licence']);
        $this->assertSame([2, 0], [$d['days'], $d['dependency_days']]);
        $this->assertSame([2, 0], [$e['days'], $e['dependency_days']], 'Even a real dependency is a start condition on a 2-day job.');

        // Larger jobs keep their dependency time: the membership build stays at 14 days.
        $a = Turnaround::plan('New build: 2-6 weeks', 'new_build', 35, ['Lemon Squeezy account and API access', 'ARMember licence', 'Course videos from the client']);
        $this->assertSame([14, 2], [$a['days'], $a['dependency_days']]);
        $noReal = Turnaround::plan('New build: 2-6 weeks', 'new_build', 35, ['WordPress admin access', 'Course videos from the client']);
        $this->assertSame([10, 0], [$noReal['days'], $noReal['dependency_days']], 'Only ordinary inputs: no extra days.');
    }

    public function test_the_checkout_brief_is_drafted_with_two_days(): void
    {
        app(Settings::class)->set(['turnaround_notes' => 'Checkout: 1-4 days']);
        $project = $this->briefProject('D', [
            'category' => 'checkout_payment', 'estimated_hours' => 5,
            'dependencies' => ['WordPress admin access', 'Stripe and PayPal dashboard access'],
        ]);
        // Two replies: the stock text may fail this brief's checks; only delivery and the prompt matter here.
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5')->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertSame(2, $draft->delivery_days);
        $this->assertStringContainsString('2 working days with a buffer for testing', $this->claude->requests[0]->userContent);
    }

    // --- 3. Profitability ------------------------------------------------------

    public function test_an_unprofitable_marketplace_bid_is_warned_and_offered_as_a_first_phase_only(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25, 'platform_fee_percent' => 10]);
        $j = self::briefs()['J']['details'];

        $p = app(PricingCalculator::class)->price(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1] + $j, 160);

        $this->assertSame(200.0, $p['amount'], 'The bid is not raised past the client\'s budget.');
        $this->assertSame(
            'Below your hourly target: USD 200 for about 160 hours is USD 1.13/hour after the fee (target USD 25). '
            .'Options: bid USD 4445 for the full scope (above the client\'s budget of USD 200), offer a first phase of about 7 hours for USD 200, or skip it.',
            $p['profit_warning'],
        );
        $this->assertSame(7, $p['covered_hours']);
        $this->assertTrue($p['reduce_scope']);
    }

    public function test_profitable_and_small_jobs_are_left_alone(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25, 'platform_fee_percent' => 10]);
        $calc = app(PricingCalculator::class);
        $usd = ['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1];

        $cards = $calc->price($usd + ['budget_min' => 30, 'budget_max' => 250, 'bid_count' => 25, 'bid_avg' => 90], 4);
        $this->assertNull($cards['profit_warning'], '86 for 4 hours is about 19/hour: fine.');

        $cheapFix = $calc->price($usd + ['budget_min' => 10, 'budget_max' => 30, 'bid_count' => 9, 'bid_avg' => 20], 4);
        $this->assertNotNull($cheapFix['profit_warning'], 'Warned...');
        $this->assertFalse($cheapFix['reduce_scope'], '...but a small fix is never cut into phases.');

        $hourly = $calc->price(['type' => 'hourly', 'currency' => 'USD', 'exchange_rate' => 1, 'budget_min' => 15, 'budget_max' => 35], 160);
        $this->assertNull($hourly['profit_warning'] ?? null);
    }

    public function test_the_marketplace_draft_carries_the_warning_and_a_first_phase_scope(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25, 'platform_fee_percent' => 10, 'turnaround_notes' => 'New build: 2-8 weeks']);
        $project = $this->briefProject('J', ['category' => 'new_build', 'estimated_hours' => 160, 'dependencies' => ['Payment provider payout API']]);
        // Two replies: the stock text may fail this brief's checks; only delivery and the prompt matter here.
        $this->claude->reply($this->draftReply(), 'claude-sonnet-5-5')->reply($this->draftReply(), 'claude-sonnet-5-5');

        $draft = app(Drafter::class)->draft($project);

        $this->assertNotNull($draft->profitWarning());
        $this->assertTrue($draft->strategy['first_phase_only']);
        $this->assertSame(2, $draft->delivery_days, 'Delivered on the 7 hours the price covers, not 160.');
        $this->assertSame(200.0, (float) $draft->bid_amount);
        $this->assertStringContainsString('Do not offer the whole scope for this price', $this->claude->requests[0]->userContent);
    }

    // --- 4. A draft that still fails a hard check needs manual review -----------

    public function test_a_draft_failing_after_the_retry_is_flagged_and_not_sent_as_ready(): void
    {
        config(['bidradar.telegram.bot_token' => '123:abc', 'bidradar.telegram.chat_id' => '555']);
        app(Settings::class)->set(['turnaround_notes' => 'Fix: 1-2 days']);
        $sent = [];
        Http::fake(['api.telegram.org/*' => function (Request $r) use (&$sent) {
            $sent[] = $r['text'];

            return Http::response(['ok' => true]);
        }]);
        $project = $this->makeProject(['source' => 'freelancer'], ['bid_count' => 3]);
        $this->claude->reply($this->scoringReply(['fit_score' => 90]))
            ->reply($this->draftReply(['proposal_text' => 'Too short.']), 'claude-sonnet-5-5')
            ->reply($this->draftReply(['proposal_text' => 'Still too short.']), 'claude-sonnet-5-5');

        ScoreProject::dispatchSync($project->id);

        $draft = $project->fresh()->latestDraft;
        $this->assertTrue($draft->needsReview());
        $this->assertSame(2, $draft->attempts);
        $this->assertCount(3, $this->claude->requests, 'Scoring plus two drafting attempts: retries stay bounded.');

        $followUp = implode("\n", array_slice($sent, 1));
        $this->assertStringContainsString('Needs manual review', $followUp);
        $this->assertStringNotContainsString('Still too short.', $followUp, 'The failing text is not sent as copy-ready.');

        $this->actingAs(User::factory()->create());
        Livewire::test(ProjectShow::class, ['project' => $project])
            ->assertSee('Needs manual review: not ready to send')
            ->assertSee('Copy anyway (needs review)')
            ->assertDontSee('Copy proposal');
    }

    public function test_a_passing_draft_is_still_sent_ready_to_copy(): void
    {
        $draft = Draft::query()->make(['check_failures' => null, 'warnings' => ['Below your hourly target: USD 50 for about 8 hours...']]);

        $this->assertFalse($draft->needsReview());
        $this->assertStringStartsWith('Below your hourly target', $draft->profitWarning());
    }
}
