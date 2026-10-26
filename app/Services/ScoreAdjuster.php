<?php

namespace App\Services;

use App\Models\Project;

/**
 * Turns the AI's base fit score into the score BidRadar acts on.
 * The base score stays in scoring['fit_score']; the adjusted score is
 * projects.fit_score, which every threshold uses. Re-run whenever bid
 * counts change: it updates the score but never triggers drafts or alerts.
 */
class ScoreAdjuster
{
    /**
     * @param  array<string, mixed>  $scoring  normalized scoring reply (base fit_score inside)
     * @param  array<string, mixed>  $details  project details (bid_count etc.)
     * @return array{base: int, adjustments: list<array{label: string, points: int}>, score: int}
     */
    public function adjust(array $scoring, array $details): array
    {
        $base = (int) ($scoring['fit_score'] ?? 0);
        $adjustments = [];

        $bids = (int) ($details['bid_count'] ?? 0);
        if ($bids >= 31) {
            $adjustments[] = ['label' => "{$bids} bids (31+)", 'points' => -25];
        } elseif ($bids >= 16) {
            $adjustments[] = ['label' => "{$bids} bids (16-30)", 'points' => -10];
        }

        $score = max(0, min(100, $base + array_sum(array_column($adjustments, 'points'))));

        return ['base' => $base, 'adjustments' => $adjustments, 'score' => $score];
    }

    /** Recompute and save the adjusted score for a scored project. No other side effects. */
    public function apply(Project $project): void
    {
        if (! is_array($project->scoring) || ! isset($project->scoring['fit_score'])) {
            return;
        }

        $result = $this->adjust($project->scoring, $project->details ?? []);
        $project->forceFill([
            'fit_score' => $result['score'],
            'scoring' => array_merge($project->scoring, ['adjustments' => $result['adjustments']]),
        ])->save();
    }
}
