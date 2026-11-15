<?php

namespace Tests\Feature;

use App\Services\ScoreAdjuster;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freelancer ranks a bid partly on the bidder's record in the project's skills, so projects tagged
 * with my top-ranked skills score higher and projects in skills I have no ranking for score lower.
 */
class SkillRankScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(Settings::class)->set([
            'skill_rank_scoring' => true,
            'skill_rankings' => ['WordPress | 9', 'Plugin | 4', 'PHP | 15', 'Shopify | 24', 'Elementor | 100', 'Graphic Design | 44'],
        ]);
    }

    private function adjust(array $skills, int $base = 60, array $details = []): array
    {
        return app(ScoreAdjuster::class)->adjust(['fit_score' => $base], array_merge(['skills' => $skills, 'bid_count' => 0], $details));
    }

    public function test_two_strong_skills_add_ten_points(): void
    {
        $r = $this->adjust(['WordPress', 'plugin', 'PHP']);

        $this->assertSame(70, $r['score']);
        // Matching ignores case; the label keeps the project's own spelling.
        $this->assertSame('strong skill ranks (plugin top 4%, WordPress top 9%)', $r['adjustments'][0]['label']);
    }

    public function test_one_strong_skill_adds_five_points(): void
    {
        $r = $this->adjust(['WordPress', 'Shopify', 'Website Design']);

        $this->assertSame(65, $r['score']);
        $this->assertSame(5, $r['adjustments'][0]['points']);
    }

    public function test_mid_ranked_skills_change_nothing(): void
    {
        $this->assertSame(60, $this->adjust(['Shopify', 'PHP'])['score']);
    }

    public function test_no_skill_in_my_top_25_percent_takes_ten_points(): void
    {
        // Elementor has no history ("| 100"), Graphic Design is top 44%, Cloud Computing is not ranked at all.
        $r = $this->adjust(['Elementor', 'Graphic Design', 'Cloud Computing']);

        $this->assertSame(50, $r['score']);
        $this->assertSame(-10, $r['adjustments'][0]['points']);
    }

    public function test_projects_without_skills_or_rankings_are_left_alone(): void
    {
        $this->assertSame(60, $this->adjust([])['score']);

        app(Settings::class)->set(['skill_rankings' => []]);
        $this->assertSame(60, $this->adjust(['Cloud Computing'])['score']);
    }

    public function test_the_setting_turns_it_off(): void
    {
        app(Settings::class)->set(['skill_rank_scoring' => false]);

        $r = $this->adjust(['WordPress', 'Plugin']);
        $this->assertSame(60, $r['score']);
        $this->assertSame([], $r['adjustments']);
    }

    public function test_it_adds_to_the_other_adjustments(): void
    {
        // 40 bids on a small fixed job, not fresh: -25, then +10 for strong skills.
        $r = $this->adjust(['WordPress', 'Plugin'], 60, ['bid_count' => 40, 'budget_min' => 30, 'budget_max' => 100]);

        $this->assertSame(45, $r['score']);
        $this->assertCount(2, $r['adjustments']);
    }
}
