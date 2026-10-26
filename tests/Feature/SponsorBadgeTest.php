<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings;
use App\Services\SponsorBadge;
use App\Services\Telegram\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SponsorBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function project(array $overrides = [], array $details = [])
    {
        return $this->makeProject(array_merge(['fit_score' => 80], $overrides), array_merge([
            'budget_min' => 50, 'budget_max' => 150, 'bid_count' => 10,
            'client' => ['payment_verified' => true, 'rating' => 4.9, 'projects' => 4],
        ], $details), $this->scoringReply(['fit_score' => 80]));
    }

    private function applies($project): bool
    {
        return app(SponsorBadge::class)->applies($project->fresh());
    }

    public function test_all_conditions_met_shows_the_badge(): void
    {
        $this->assertTrue($this->applies($this->project()));
    }

    public function test_each_condition_is_required(): void
    {
        $this->assertFalse($this->applies($this->project(['fit_score' => 74])), 'score under Auto-draft (75)');
        $this->assertFalse($this->applies($this->project([], ['bid_count' => 16])), 'more than 15 bids');
        $this->assertTrue($this->applies($this->project([], ['bid_count' => 15])), '15 bids is fine');
        $this->assertFalse($this->applies($this->project([], ['client' => ['payment_verified' => false, 'rating' => 4.9]])), 'unverified');
        $this->assertFalse($this->applies($this->project([], ['client' => ['payment_verified' => true, 'rating' => 4.7]])), 'rating under 4.8');
        $this->assertFalse($this->applies($this->project([], ['budget_max' => 99])), 'budget top under $100');
        $this->assertFalse($this->applies($this->project([], ['currency' => 'INR', 'exchange_rate' => 0.012, 'budget_max' => 5000])), 'INR 5000 = $60');
    }

    public function test_the_minimum_budget_is_a_setting(): void
    {
        app(Settings::class)->set(['sponsor_min_budget_usd' => 200]);

        $this->assertFalse($this->applies($this->project()));
        $this->assertTrue($this->applies($this->project([], ['budget_max' => 250])));
    }

    public function test_badge_shows_on_the_card_and_in_the_alert(): void
    {
        $this->actingAs(User::factory()->create());
        $good = $this->project(['title' => 'Good one']);

        $this->get('/')->assertSee('Worth sponsoring');
        $this->assertStringContainsString('Worth sponsoring', Telegram::alertText($good->fresh()));
        $this->assertStringNotContainsString('Worth sponsoring', Telegram::alertText($this->project([], ['bid_count' => 40])->fresh()));
    }
}
