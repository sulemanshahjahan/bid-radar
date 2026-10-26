<?php

namespace App\Services;

use App\Models\Project;
use App\Services\Freelancer\RulesFilter;

/**
 * Decides whether a project may be drafted automatically. Applies to pasted
 * and live projects alike: the rules-filter reason, unverified client payment,
 * a new build with too many bids, and a score below the "show" threshold all
 * block auto-drafting.
 * The owner can still draft anyway from the project page.
 */
class DraftGate
{
    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> Reasons drafting is blocked (empty = allowed). */
    public function blockers(Project $project): array
    {
        $reasons = [];

        if ($project->filter_reason) {
            $reasons[] = lcfirst($project->filter_reason);
        }

        if ($payment = RulesFilter::paymentWarning($project->details ?? [])) {
            $reasons[] = $payment;
        }

        $bids = (int) $project->detail('bid_count', 0);
        $limit = $this->settings->int('new_build_max_bid_count');
        if ($project->category === 'new_build' && $bids >= $limit && ! str_starts_with((string) $project->filter_reason, 'New build')) {
            $reasons[] = "new build, {$bids} bids (limit {$limit})";
        }

        $min = $this->settings->int('threshold_medium');
        if ($project->fit_score !== null && $project->fit_score < $min) {
            $reasons[] = "score {$project->fit_score} (min {$min})";
        }

        return $reasons;
    }

    /** True when the only thing blocking is the client's payment status (still worth an alert). */
    public function onlyPaymentBlocks(Project $project): bool
    {
        $reasons = $this->blockers($project);

        return $reasons !== [] && array_diff($reasons, [RulesFilter::paymentWarning($project->details ?? [])]) === [];
    }

    public function message(Project $project): ?string
    {
        $reasons = $this->blockers($project);

        return $reasons === [] ? null : 'Filtered: '.implode(', ', $reasons);
    }
}
