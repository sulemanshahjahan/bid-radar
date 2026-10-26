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
        $fresh = self::isFresh($postedAt);
        if (! $fresh && $bids >= 31) {
            $adjustments[] = ['label' => "{$bids} bids (31+)", 'points' => -25];
        } elseif (! $fresh && $bids >= 16) {
            $adjustments[] = ['label' => "{$bids} bids (16-30)", 'points' => -10];
        }

        if ($this->settings->get('rank_rebuild')) {
            array_push($adjustments, ...$this->rankRebuild($scoring, $details, $text));
        }

        $score = max(0, min(100, $base + array_sum(array_column($adjustments, 'points'))));

        return ['base' => $base, 'adjustments' => $adjustments, 'score' => $score];
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

    /**
     * Rank rebuild mode: favour quick wins from good clients, avoid price shoppers and big vague jobs.
     *
     * @return list<array{label: string, points: int}>
     */
    private function rankRebuild(array $scoring, array $details, string $text): array
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

        if (($scoring['large_vague_or_off_niche'] ?? null) === true) {
            $out[] = ['label' => 'large or off-niche', 'points' => -20];
        }

        return $out;
    }
}
