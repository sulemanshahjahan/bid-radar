<?php

namespace Tests;

use App\Models\Project;
use App\Services\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeClaudeClient;

abstract class TestCase extends BaseTestCase
{
    protected FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never call the real Claude API.
        $this->claude = new FakeClaudeClient;
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    /** @param array<string, mixed> $overrides */
    protected function makeProject(array $overrides = [], array $details = [], ?array $scoring = null): Project
    {
        return Project::query()->create(array_merge([
            'source' => 'paste',
            'title' => 'WooCommerce checkout failing after update',
            'description' => 'After updating WooCommerce to 9.3 the checkout page spins forever and no orders come through. Stripe gateway. Need it fixed today.',
            'details' => array_merge([
                'type' => 'fixed', 'budget_min' => 150, 'budget_max' => 250, 'currency' => 'USD',
                'exchange_rate' => 1, 'bid_count' => 3, 'bid_avg' => 180, 'skills' => ['WooCommerce', 'PHP'],
                'client' => ['payment_verified' => true, 'rating' => 4.8, 'country' => 'US'],
            ], $details),
            'scoring' => $scoring,
            'category' => $scoring['category'] ?? null,
            'fit_score' => $scoring['fit_score'] ?? null,
            'ai_state' => $scoring ? 'scored' : 'queued',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    protected function scoringReply(array $overrides = []): array
    {
        return array_merge([
            'fit_score' => 88,
            'category' => 'fix',
            'reason' => 'Specific checkout bug, matches payment gateway work',
            'client_test_phrase' => null,
            'client_questions' => [],
            'red_flags' => [],
            'brief_summary' => 'Checkout broken after WooCommerce update. Stripe orders not completing.',
        ], $overrides);
    }

    /** A draft reply that passes every post-check for makeProject() (bid 171 = 180 x 0.95). */
    protected function draftReply(array $overrides = []): array
    {
        return array_merge([
            'proposal_text' => 'Your checkout spins forever after the WooCommerce 9.3 update, so Stripe orders never complete. '
                .'I would trace the failing checkout request and fix the plugin conflict or outdated template that is breaking it. '
                .'First I would copy the site to staging and reproduce the spinning checkout with a test Stripe payment. '
                .'Then I would read the PHP error log and browser console to find the exact request that fails, and fix the conflict or template override. '
                .'Finally I would run test orders end to end on staging before applying the same fix to the live site. '
                .'Did the problem start right after the update, or after another plugin was updated at the same time?',
            'bid_amount' => 171,
            'currency' => 'USD',
            'delivery_days' => 2,
            'milestones' => [['title' => 'Diagnose and fix on staging', 'amount' => 100], ['title' => 'Apply fix live and test orders', 'amount' => 71]],
            'price_reason' => 'Near the average bid',
            'questions_to_ask_later' => ['Staging access?'],
        ], $overrides);
    }
}
