<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Freelancer\RulesFilter;
use Illuminate\Support\Carbon;

/**
 * Turns the AI's base fit score into the score BidRadar acts on.
 * The base score stays in scoring['fit_score']; the adjusted score is
 * projects.fit_score, which every threshold uses. Re-run whenever bid
 * counts change: it updates the score but never triggers drafts or alerts.
 */
class ScoreAdjuster
{
    public const PRICE_SHOPPER_WORDS = ['cheap', 'cheapest', 'lowest price', 'low budget'];

    /** Popular fix jobs collect dozens of bids in the first minutes; that is not "late". */
    public const FRESH_MINUTES = 10;

    /** Budgets at or above these get the smaller bid-count penalty (ScoreAdjuster::wellFunded). */
    public const WELL_FUNDED_FIXED_USD = 250;

    public const WELL_FUNDED_HOURLY_USD = 25;

    /** A base score under this already reflects off-niche work, so "large or off-niche" does not take more. */
    public const OFF_NICHE_BASE = 40;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $scoring  normalized scoring reply (base fit_score inside)
     * @param  array<string, mixed>  $details  project details (bid_count, client, budget...)
     * @param  string  $text  title + description, for price-shopper wording
     * @param  Carbon|null  $postedAt  live projects only: no bid penalty in the first FRESH_MINUTES
     * @return array{base: int, adjustments: list<array{label: string, points: int}>, score: int}
     */
    public function adjust(array $scoring, array $details, string $text = '', ?Carbon $postedAt = null): array
    {
        $base = (int) ($scoring['fit_score'] ?? 0);
        $adjustments = [];

        $bids = (int) ($details['bid_count'] ?? 0);
        // Scores are re-adjusted on every poll, so the penalty applies once a fresh project ages.
        // Well-funded projects attract many bids and are still worth a bid: smaller penalty.
        $fresh = self::isFresh($postedAt);
        $funded = self::wellFunded($details);
        if (! $fresh && $bids >= 31) {
            $adjustments[] = ['label' => "{$bids} bids (31+)", 'points' => $funded ? -10 : -25];
        } elseif (! $fresh && $bids >= 16) {
            $adjustments[] = ['label' => "{$bids} bids (16-30)", 'points' => $funded ? -5 : -10];
        }

        if ($this->settings->get('rank_rebuild')) {
            array_push($adjustments, ...$this->rankRebuild($scoring, $details, $text, $base));
        }

        if ($this->settings->get('skill_rank_scoring')) {
            array_push($adjustments, ...$this->skillRanks((array) ($details['skills'] ?? [])));
        }

        $score = max(0, min(100, $base + array_sum(array_column($adjustments, 'points'))));

        return ['base' => $base, 'adjustments' => $adjustments, 'score' => $score];
    }

    /**
     * The client's minimum budget (in USD; the top when there is no minimum) is at least
     * WELL_FUNDED_FIXED_USD for fixed-price work, or WELL_FUNDED_HOURLY_USD an hour for hourly work.
     *
     * @param  array<string, mixed>  $details
     */
    public static function wellFunded(array $details): bool
    {
        $floor = (float) (($details['budget_min'] ?? 0) ?: ($details['budget_max'] ?? 0)) * (float) (($details['exchange_rate'] ?? 1) ?: 1);

        return ($details['type'] ?? 'fixed') === 'hourly'
            ? $floor >= self::WELL_FUNDED_HOURLY_USD
            : $floor >= self::WELL_FUNDED_FIXED_USD;
    }

    /** Posted less than FRESH_MINUTES ago (live projects pass posted_at, pasted ones null). */
    public static function isFresh(?Carbon $postedAt): bool
    {
        return $postedAt !== null && $postedAt->diffInMinutes(now(), true) < self::FRESH_MINUTES;
    }

    /**
     * adjust() with the project's own details, text and (live projects only) posting time.
     *
     * @param  array<string, mixed>  $scoring
     * @return array{base: int, adjustments: list<array{label: string, points: int}>, score: int}
     */
    public function forProject(array $scoring, Project $project): array
    {
        return $this->adjust($scoring, $project->details ?? [], $project->title."\n".$project->description,
            $project->source === 'freelancer' ? $project->posted_at : null);
    }

    /** Recompute and save the adjusted score for a scored project. No other side effects. */
    public function apply(Project $project): void
    {
        if (! is_array($project->scoring) || ! isset($project->scoring['fit_score'])) {
            return;
        }

        $result = $this->forProject($project->scoring, $project);
        $project->forceFill([
            'fit_score' => $result['score'],
            'scoring' => array_merge($project->scoring, ['adjustments' => $result['adjustments']]),
        ])->save();
    }

    /** A project skill ranked at or above this "top %" counts as a strong skill. */
    public const STRONG_SKILL_TOP = 10;

    /** A project with no skill ranked at or above this "top %" gets the weak-skills penalty. */
    public const WEAK_SKILL_TOP = 25;

    /**
     * Freelancer ranks bids partly on the bidder's record in the skills the project is tagged with,
     * so the owner's per-skill rankings ("Skill | top %", Settings > skill_rankings) predict where a bid
     * will land: top 1-10% skills rank in the top third, untagged or unranked skills near the bottom.
     * Matching is by exact skill name (case-insensitive), as Freelancer tags them; "| 100" means no history.
     *
     * @param  list<string>  $projectSkills
     * @return list<array{label: string, points: int}>
     */
    public function skillRanks(array $projectSkills): array
    {
        $projectSkills = array_values(array_filter(array_map(fn ($s) => trim((string) $s), $projectSkills), 'strlen'));
        if ($projectSkills === []) {
            return [];
        }

        $rank = [];
        foreach (\App\Services\Opportunity\ProfileEvidence::parseRankings($this->settings->list('skill_rankings')) as $r) {
            $rank[mb_strtolower($r['skill'])] = $r['top'];
        }
        if ($rank === []) {
            return [];
        }

        $matched = [];
        foreach ($projectSkills as $skill) {
            $top = $rank[mb_strtolower($skill)] ?? null;
            if ($top !== null && $top < 100) {
                $matched[$skill] = $top;
            }
        }
        asort($matched);
        $strong = array_filter($matched, fn ($top) => $top <= self::STRONG_SKILL_TOP);
        $fmt = fn (array $skills) => implode(', ', array_map(fn ($s, $t) => $s.' top '.rtrim(rtrim(number_format($t, 1), '0'), '.').'%', array_keys($skills), $skills));

        if (count($strong) >= 2) {
            return [['label' => 'strong skill ranks ('.$fmt(array_slice($strong, 0, 3, true)).')', 'points' => 10]];
        }
        if (count($strong) === 1) {
            return [['label' => 'strong skill rank ('.$fmt($strong).')', 'points' => 5]];
        }
        if (array_filter($matched, fn ($top) => $top <= self::WEAK_SKILL_TOP) === []) {
            return [['label' => 'no project skill ranked in my top '.self::WEAK_SKILL_TOP.'% (bid likely ranks low)', 'points' => -10]];
        }

        return [];
    }

    /**
     * Rank rebuild mode: favour quick wins from good clients, avoid price shoppers and big vague jobs.
     *
     * @return list<array{label: string, points: int}>
     */
    private function rankRebuild(array $scoring, array $details, string $text, int $base): array
    {
        $out = [];
        $client = $details['client'] ?? [];

        if (($scoring['quick_fix'] ?? null) === true) {
            $out[] = ['label' => 'quick fix (1-2 days)', 'points' => 10];
        }

        if ((float) ($client['rating'] ?? 0) >= 4.8 && (int) ($client['projects'] ?? 0) >= 3) {
            $out[] = ['label' => 'client 4.8+ with 3+ projects', 'points' => 10];
        }

        if (($client['payment_verified'] ?? null) !== true) {
            $out[] = ['label' => 'payment not verified', 'points' => -15];
        }

        $word = RulesFilter::firstMatch(self::PRICE_SHOPPER_WORDS, $text);
        $top = (float) max($details['budget_max'] ?? 0, $details['budget_min'] ?? 0) * (float) (($details['exchange_rate'] ?? 1) ?: 1);
        $lowBudget = ($details['type'] ?? 'fixed') === 'fixed' && $top > 0 && $top < $this->settings->float('min_budget_usd');
        if ($word !== null || $lowBudget) {
            $out[] = ['label' => $word !== null ? "price shopper (\"{$word}\")" : sprintf('budget top under minimum ($%.0f)', $top), 'points' => -20];
        }

        // A low base score already counts the job as off-niche: subtracting again would bury it at 0.
        if (($scoring['large_vague_or_off_niche'] ?? null) === true && $base >= self::OFF_NICHE_BASE) {
            $out[] = ['label' => 'large or off-niche', 'points' => -20];
        }

        return $out;
    }
}
