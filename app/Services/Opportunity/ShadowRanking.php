<?php

namespace App\Services\Opportunity;

use App\Models\BidSnapshot;
use App\Models\Project;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shadow mode: evaluates scored projects still inside the 24-hour data window, next to the
 * existing score. Read-only. A failed evaluation is logged with the project ID and the error
 * class only (no project text) and never affects anything else.
 */
class ShadowRanking
{
    public const NOW = 'now';

    /** As BidRadar saw the project when it scored it: bid snapshot at or before scored_at, decision-time score. */
    public const DECISION = 'decision';

    public function __construct(private readonly OpportunityScorer $scorer, private readonly Settings $settings) {}

    public static function enabled(): bool
    {
        return config('bidradar.opportunity.enabled') === true;
    }

    /**
     * @return list<array{project: Project, existing: int|null, opportunity: Opportunity|null, error: bool}>
     */
    public function rows(string $at = self::NOW, int $hours = 24): array
    {
        $projects = Project::query()
            ->whereNull('purged_at')
            ->where('ai_state', '!=', 'filtered')
            ->whereNotNull('scored_at')
            ->where('created_at', '>=', now()->subHours($hours))
            ->with('latestDraft')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $snapshots = BidSnapshot::query()->whereIn('project_id', $projects->modelKeys())
            ->get(['id', 'project_id', 'bid_count', 'recorded_at'])->groupBy('project_id');
        $profile = ProfileEvidence::fromSettings($this->settings);

        return $projects->map(fn (Project $p) => [
            'project' => $p,
            'existing' => $at === self::DECISION ? self::decisionScore($p) : $p->fit_score,
        ] + $this->safeEvaluate($p, $profile, $at === self::DECISION ? $p->scored_at : null, $snapshots->get($p->id, collect())))->all();
    }

    /** One project now, for the project page. Null when disabled, purged, not scored or failed. */
    public function forProject(Project $project): ?Opportunity
    {
        if (! self::enabled() || $project->purged_at !== null || ! is_array($project->scoring)) {
            return null;
        }

        return $this->safeEvaluate($project, ProfileEvidence::fromSettings($this->settings), null)['opportunity'];
    }

    /** The adjusted score BidRadar acted on when it decided (falls back to the current score for older projects). */
    public static function decisionScore(Project $project): ?int
    {
        $score = $project->decision['score'] ?? null;

        return is_numeric($score) ? (int) $score : $project->fit_score;
    }

    /** @return array{opportunity: Opportunity|null, error: bool} */
    public function safeEvaluate(Project $project, ProfileEvidence $profile, ?Carbon $asOf, $snapshots = null): array
    {
        try {
            return ['opportunity' => $this->scorer->evaluate(OpportunityInput::fromProject($project, $asOf, $snapshots), $profile), 'error' => false];
        } catch (Throwable $e) {
            // Sanitized: an exception message could quote project text.
            Log::warning('opportunity evaluation failed', ['project_id' => $project->id, 'error' => $e::class]);

            return ['opportunity' => null, 'error' => true];
        }
    }

    /**
     * Rank positions (1 = best) by a score; ties keep the newer project first. Null scores rank last.
     *
     * @param  array<int, int|null>  $scores  project id => score
     * @return array<int, int> project id => rank
     */
    public static function ranks(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $copy = $scores;
        uksort($scores, fn ($a, $b) => [$copy[$b] ?? -1, $b] <=> [$copy[$a] ?? -1, $a]);

        return array_combine(array_keys($scores), range(1, count($scores)));
    }
}
