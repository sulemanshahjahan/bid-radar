<?php

namespace App\Services;

use App\Models\Project;

/**
 * "Worth sponsoring": a project good enough to consider paying to promote the bid.
 * Adjusted score at or above Auto-draft, 15 bids or fewer, verified client rated
 * 4.8+, and a budget top (in USD) at or above the Settings minimum.
 */
class SponsorBadge
{
    public function __construct(private readonly Settings $settings) {}

    public function applies(Project $project): bool
    {
        $d = $project->details ?? [];
        $client = $d['client'] ?? [];
        $top = (float) max($d['budget_max'] ?? 0, $d['budget_min'] ?? 0) * (float) (($d['exchange_rate'] ?? 1) ?: 1);

        return $project->fit_score !== null
            && $project->fit_score >= $this->settings->int('threshold_high')
            && isset($d['bid_count']) && (int) $d['bid_count'] <= 15
            && ($client['payment_verified'] ?? null) === true
            && (float) ($client['rating'] ?? 0) >= 4.8
            && $top >= $this->settings->float('sponsor_min_budget_usd');
    }
}
