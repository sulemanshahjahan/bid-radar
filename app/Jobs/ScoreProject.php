<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\CostTracker;
use App\Services\DraftGate;
use App\Services\Freelancer\RulesFilter;
use App\Services\ScoreAdjuster;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use App\Services\Telegram\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ScoreProject implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $projectId) {}

    /** The job died outside handle()'s own error handling (e.g. a database lock): never leave it "queued". */
    public function failed(?Throwable $e): void
    {
        Project::query()->whereKey($this->projectId)->whereIn('ai_state', ['queued', 'scoring'])
            ->update(['ai_state' => 'failed', 'ai_error' => 'Scoring failed: '.mb_substr((string) $e?->getMessage(), 0, 300)]);
    }

    public function handle(Scorer $scorer, Settings $settings, Telegram $telegram, DraftGate $gate, ScoreAdjuster $adjuster, CostTracker $costs): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project === null || $project->purged_at !== null) {
            return;
        }

        $project->update(['ai_state' => 'scoring', 'ai_error' => null]);

        try {
            $result = $scorer->score($project);
        } catch (Throwable $e) {
            $project->update(['ai_state' => 'failed', 'ai_error' => 'Scoring failed: '.$e->getMessage()]);

            return;
        }

        // scoring['fit_score'] keeps the AI's base score; fit_score is the adjusted one every threshold uses.
        $adjusted = $adjuster->forProject($result, $project);
        $project->update([
            'scoring' => array_merge($result, ['adjustments' => $adjusted['adjustments']]),
            'fit_score' => $adjusted['score'],
            'category' => $result['category'],
            'ai_state' => 'scored',
            'scored_at' => now(),
        ]);

        $high = $adjusted['score'] >= $settings->int('threshold_high');
        $medium = $adjusted['score'] >= $settings->int('threshold_medium');
        $live = $project->source === 'freelancer';
        $paused = (bool) $settings->get('paused');

        // Filtered projects (rules, new build with too many bids, low score) are never drafted
        // or alerted automatically, pasted or live. The owner can still draft anyway.
        $project = $project->fresh();
        if ($blockers = $gate->blockers($project)) {
            // High fit held back only by unverified payment: still alert me (without a draft) so I can decide.
            $paymentAlert = $live && $high && $gate->onlyPaymentBlocks($project) && ! $paused;
            $this->recordDecision($project, $result, $adjusted, $settings, 'held', $blockers);
            if ($paymentAlert) {
                $telegram->projectAlert($project, note: 'Not drafted: '.RulesFilter::paymentWarning($project->details ?? []).'. Open to draft anyway.');
            }

            return;
        }

        // High fit: alert FIRST (seconds count), then draft; the draft follows as silent messages.
        // Paste mode can ask to always draft (no alerts for pasted projects).
        if ($project->auto_draft || $high) {
            $alerting = $live && $high && ! $paused;
            $capReached = $costs->capReached();
            $this->recordDecision($project, $result, $adjusted, $settings, match (true) {
                $alerting && $capReached => 'alert_cap_reached',
                $alerting => 'alert_and_draft',
                default => 'draft_only',
            });
            if ($alerting) {
                $telegram->projectAlert($project, capReached: $capReached, draftComing: ! $capReached);
            }
            DraftProject::dispatch($project->id, alert: $alerting && ! $capReached);

            return;
        }

        // Medium fit: listed in the feed; optional quiet alert.
        $quiet = $live && $medium && $settings->get('quiet_alerts') && ! $paused;
        $this->recordDecision($project, $result, $adjusted, $settings, match (true) {
            $quiet => 'quiet_alert',
            $medium => 'feed',
            default => 'hidden',
        });
        if ($quiet) {
            $telegram->projectAlert($project->fresh(), quiet: true);
        }
    }

    /**
     * BidRadar's own decision for later analysis (projects.decision, kept after the purge). Only scores,
     * rule labels and codes: no brief text, and raw bid counts or budgets are stripped from the labels.
     * Client payment status is Freelancer data, so it is never recorded here, not even as a blocker code
     * ("held" covers every gate block, including payment).
     *
     * @param  array<string, mixed>  $result  normalized scoring reply
     * @param  array{base: int, adjustments: list<array{label: string, points: int}>, score: int}  $adjusted
     * @param  list<string>  $blockers
     */
    private function recordDecision(Project $project, array $result, array $adjusted, Settings $settings, string $decision, array $blockers = []): void
    {
        $project->recordDecision([
            'decided_at' => now()->toIso8601String(),
            'scoring_model' => $settings->get('scoring_model'),
            'rank_rebuild' => (bool) $settings->get('rank_rebuild'),
            'base_score' => $adjusted['base'],
            'score' => $adjusted['score'],
            'adjustments' => array_map(fn ($a) => ['label' => self::ruleLabel($a['label']), 'points' => $a['points']], $adjusted['adjustments']),
            'category' => $result['category'] ?? null,
            'estimated_hours' => $result['estimated_hours'] ?? null,
            'quick_fix' => $result['quick_fix'] ?? null,
            'large_vague_or_off_niche' => $result['large_vague_or_off_niche'] ?? null,
            'threshold_high' => $settings->int('threshold_high'),
            'threshold_medium' => $settings->int('threshold_medium'),
            'decision' => $decision,
            'blockers' => array_values(array_unique(array_filter(array_map(self::blockerCode(...), $blockers)))),
        ]);
    }

    /** "123 bids (31+)" -> "bids (31+)", "budget top under minimum ($12)" -> "budget top under minimum". */
    public static function ruleLabel(string $label): string
    {
        return trim((string) preg_replace(['/^\d+\s+bids\b/', '/\s*\(\$[\d.,]+\)/'], ['bids', ''], $label));
    }

    /** Null for the payment blocker: client payment status is not retained. */
    public static function blockerCode(string $blocker): ?string
    {
        return match (true) {
            str_contains($blocker, 'payment') => null,
            str_starts_with($blocker, 'new build') => 'new_build_bid_limit',
            str_starts_with($blocker, 'score ') => 'below_medium',
            default => 'rules_filter',
        };
    }
}
