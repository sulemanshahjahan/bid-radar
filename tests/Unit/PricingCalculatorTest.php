<?php

namespace Tests\Unit;

use App\Services\Drafting\PricingCalculator;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function price(array $details): array
    {
        return app(PricingCalculator::class)->price(array_merge(['type' => 'fixed', 'currency' => 'USD', 'exchange_rate' => 1], $details));
    }

    public function test_with_bids_it_bids_near_the_average_not_the_lowest(): void
    {
        $p = $this->price(['budget_min' => 100, 'budget_max' => 300, 'bid_count' => 10, 'bid_avg' => 200]);

        $this->assertSame(190.0, $p['amount']); // 200 x 0.95
        $this->assertFalse($p['is_hourly']);
    }

    public function test_without_bids_it_uses_the_middle_of_the_budget(): void
    {
        $this->assertSame(200.0, $this->price(['budget_min' => 100, 'budget_max' => 300, 'bid_count' => 0])['amount']);
    }

    public function test_never_below_the_clients_minimum_budget(): void
    {
        $p = $this->price(['budget_min' => 250, 'budget_max' => 500, 'bid_count' => 20, 'bid_avg' => 120]);

        $this->assertSame(250.0, $p['amount']);
        $this->assertSame(250.0, $p['minimum']);
    }

    public function test_never_below_my_floor(): void
    {
        app(Settings::class)->set(['floor_price_usd' => 60]);

        $p = $this->price(['budget_min' => 10, 'budget_max' => 30, 'bid_count' => 5, 'bid_avg' => 20]);

        $this->assertSame(60.0, $p['amount']);
        $this->assertStringContainsString('floor', $p['reason']);
    }

    public function test_floor_is_converted_into_the_project_currency(): void
    {
        app(Settings::class)->set(['floor_price_usd' => 25]);

        // 1 INR = 0.012 USD, so a $25 floor is about INR 2084.
        $p = $this->price(['currency' => 'INR', 'exchange_rate' => 0.012, 'budget_min' => 600, 'budget_max' => 1500, 'bid_count' => 0]);

        $this->assertSame('INR', $p['currency']);
        $this->assertSame(2084.0, $p['amount']);
    }

    public function test_hourly_uses_my_rate_within_the_clients_range(): void
    {
        app(Settings::class)->set(['hourly_rate_usd' => 25]);

        $this->assertSame(25.0, $this->price(['type' => 'hourly', 'budget_min' => 15, 'budget_max' => 35])['amount']);
        $this->assertSame(30.0, $this->price(['type' => 'hourly', 'budget_min' => 30, 'budget_max' => 50])['amount']);

        $above = $this->price(['type' => 'hourly', 'budget_min' => 8, 'budget_max' => 15]);
        $this->assertSame(25.0, $above['amount']); // never below my rate
        $this->assertTrue($above['is_hourly']);
    }

    public function test_milestones_are_scaled_to_add_up_to_the_bid(): void
    {
        $m = PricingCalculator::fitMilestones([['title' => 'A', 'amount' => 50], ['title' => 'B', 'amount' => 50], ['title' => 'C', 'amount' => 100]], 171);

        $this->assertSame(171.0, array_sum(array_column($m, 'amount')));
        $this->assertCount(3, $m);
    }

    public function test_milestones_without_amounts_are_split_evenly(): void
    {
        $m = PricingCalculator::fitMilestones([['title' => 'A', 'amount' => 0], ['title' => 'B', 'amount' => 0]], 100);

        $this->assertSame([50.0, 50.0], array_column($m, 'amount'));
    }
}
