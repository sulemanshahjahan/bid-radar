<?php

namespace Tests;

use App\Models\Project;
use App\Services\Claude\ClaudeClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
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

        // ...and never reach Freelancer or Telegram either. Each test fakes what it needs.
        Http::preventStrayRequests();
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
            'plan' => [
                'primary_problem' => 'Checkout hangs after the WooCommerce update, so no Stripe orders complete',
                'client_priority' => 'Speed: orders are being lost',
                'instructions' => [['instruction' => 'Fixed today', 'how_met' => 'Delivery time given']],
                'hook_candidates' => [
                    ['type' => 'diagnosis', 'text' => 'A checkout that spins forever right after a WooCommerce update is usually a plugin or template conflict.'],
                    ['type' => 'risk', 'text' => 'Every hour the checkout spins is lost Stripe orders.'],
                    ['type' => 'fast_fix', 'text' => 'This is a targeted fix, not a rebuild.'],
                ],
                'hook_type' => 'diagnosis',
                'recommendation' => '',
                'proof' => 'none',
                'closing' => 'question',
            ],
            'proposal_text' => 'A checkout that spins forever right after the WooCommerce 9.3 update is usually a plugin conflict or an outdated '
                .'template override breaking the checkout request, so Stripe never gets the order. '
                .'I would copy the site to staging, reproduce it with a test Stripe payment, and read the PHP error log and browser '
                .'console to find the exact request that fails. Once the conflict or template is fixed, I run test orders end to end '
                .'on staging and apply the same fix to the live site, so nothing is changed blind on the store. '
                .'Did the problem start right after the update, or after another plugin was updated at the same time?',
            'self_review' => ['hook' => 8, 'understanding' => 8, 'expertise' => 8, 'proof' => 6, 'execution' => 8, 'timing' => 7, 'tone' => 8, 'concision' => 8],
            'bid_amount' => 171,
            'currency' => 'USD',
            'delivery_days' => 2,
            'milestones' => [['title' => 'Diagnose and fix on staging', 'amount' => 100], ['title' => 'Apply fix live and test orders', 'amount' => 71]],
            'price_reason' => 'Near the average bid',
            'questions_to_ask_later' => ['Staging access?'],
        ], $overrides);
    }
}
