<?php

namespace Tests\Feature;

use App\Services\Freelancer\ProjectMapper;
use App\Services\Freelancer\RulesFilter;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uses the saved sample response in tests/fixtures/freelancer (shape taken from
 * developers.freelancer.com; IDs and values are synthetic).
 */
class RulesFilterTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = 1793000700; // shortly after the fixture projects were posted

    /** @return array<int, array<string, mixed>> mapped projects keyed by Freelancer ID */
    private function fixture(): array
    {
        $json = json_decode(file_get_contents(base_path('tests/fixtures/freelancer/active_projects.json')), true)['result'];

        return collect($json['projects'])
            ->mapWithKeys(fn ($p) => [$p['id'] => ProjectMapper::map($p, $json['users'][(string) ($p['owner_id'] ?? '')] ?? null)])
            ->all();
    }

    private function reject(array $mapped): ?string
    {
        return app(RulesFilter::class)->reject($mapped, self::NOW);
    }

    public function test_fixture_projects_get_the_expected_verdicts(): void
    {
        $p = $this->fixture();

        $this->assertNull($this->reject($p[101]));
        $this->assertSame('Excluded keyword: SEO', $this->reject($p[102]));
        $this->assertSame('Client payment not verified', $this->reject($p[103]));
        $this->assertStringStartsWith('New build with 120 bids', $this->reject($p[104]));
        $this->assertSame('Budget too low ($20 USD)', $this->reject($p[105]));
        $this->assertNull($this->reject($p[106]), 'INR 12500 x 0.012 = $150 USD passes');
        $this->assertSame('No target skill', $this->reject($p[107]));
    }

    public function test_mapper_reads_documented_field_names(): void
    {
        $p = $this->fixture()[101];

        $this->assertSame(101, $p['freelancer_id']);
        $this->assertSame('https://www.freelancer.com/projects/php/woocommerce-checkout-failing-after-update', $p['url']);
        $this->assertSame('fixed', $p['details']['type']);
        $this->assertSame(150.0, $p['details']['budget_min']);
        $this->assertSame(3, $p['details']['bid_count']);
        $this->assertSame(['PHP', 'WooCommerce'], $p['details']['skills']);
        $this->assertSame(['urgent'], $p['details']['upgrades']);
        $this->assertSame(['payment_verified' => true, 'rating' => 4.8, 'reviews' => 9, 'projects' => 12, 'country' => 'United States'], $p['details']['client']);
    }

    public function test_client_details_are_read_from_owner_info_when_the_owner_is_hidden(): void
    {
        $p = $this->fixture()[106];

        $this->assertNull($p['details']['owner_id']);
        $this->assertSame(['payment_verified' => true, 'rating' => 4.5, 'reviews' => 3, 'projects' => 4, 'country' => 'India'], $p['details']['client']);
    }

    public function test_missing_payment_status_is_reported_as_unknown_not_unverified(): void
    {
        $p = $this->fixture()[101];
        $p['details']['client'] = [];

        $this->assertSame('Client payment status unknown', $this->reject($p));
    }

    public function test_keywords_match_whole_words_only(): void
    {
        $this->assertSame('AI', RulesFilter::firstMatch(['AI'], 'Add an AI chatbot'));
        $this->assertNull(RulesFilter::firstMatch(['AI'], 'Fix my email campaign'));
        $this->assertSame('n8n', RulesFilter::firstMatch(['n8n'], 'Set up n8n flows'));
        $this->assertSame('Make.com', RulesFilter::firstMatch(['Make.com'], 'Connect make.com to my store'));
        $this->assertNull(RulesFilter::firstMatch(['GPT'], 'Fix GPTW badge'));
    }

    public function test_bid_count_limit_and_age_limit(): void
    {
        $p = $this->fixture()[101];

        $p['details']['bid_count'] = 150;
        $this->assertSame('Too many bids (150)', $this->reject($p));

        $p['details']['bid_count'] = 3;
        $p['posted_at'] = self::NOW - 25 * 3600;
        $this->assertSame('Posted more than 24h ago', $this->reject($p));
    }

    public function test_no_budget_is_skipped_and_settings_are_respected(): void
    {
        $p = $this->fixture()[101];
        $p['details']['budget_min'] = null;
        $p['details']['budget_max'] = null;
        $this->assertSame('No budget', $this->reject($p));

        $p = $this->fixture()[105];
        app(Settings::class)->set(['min_budget_usd' => 15]);
        $this->assertNull($this->reject($p));
    }
}
