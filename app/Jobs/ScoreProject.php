<?php

namespace App\Jobs;

use App\Models\Project;
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

    public function handle(Scorer $scorer, Settings $settings, Telegram $telegram, DraftGate $gate, ScoreAdjuster $adjuster): void
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
        $adjusted = $adjuster->adjust($result, $project->details ?? []);
        $project->update([
            'scoring' => array_merge($result, ['adjustments' => $adjusted['adjustments']]),
            'fit_score' => $adjusted['score'],
            'category' => $result['category'],
            'ai_state' => 'scored',
        ]);

        $high = $adjusted['score'] >= $settings->int('threshold_high');
        $medium = $adjusted['score'] >= $settings->int('threshold_medium');
        $live = $project->source === 'freelancer';

        // Filtered projects (rules, new build with too many bids, low score) are never drafted
        // or alerted automatically, pasted or live. The owner can still draft anyway.
        $project = $project->fresh();
        if ($gate->blockers($project) !== []) {
            // High fit held back only by unverified payment: still alert me (without a draft) so I can decide.
            if ($live && $high && $gate->onlyPaymentBlocks($project) && ! $settings->get('paused')) {
                $telegram->projectAlert($project, note: 'Not drafted: '.RulesFilter::paymentWarning($project->details ?? []).'. Open to draft anyway.');
            }

            return;
        }

        // High fit is drafted automatically (then alerted); paste mode can ask to always draft.
        if ($project->auto_draft || $high) {
            DraftProject::dispatch($project->id, alert: $live && $high);

            return;
        }

        // Medium fit: listed in the feed; optional quiet alert.
        if ($live && $medium && $settings->get('quiet_alerts') && ! $settings->get('paused')) {
            $telegram->projectAlert($project->fresh(), quiet: true);
        }
    }
}
