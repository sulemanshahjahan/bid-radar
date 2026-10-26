<?php

namespace App\Jobs;

use App\Models\Project;
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

    public function handle(Scorer $scorer, Settings $settings, Telegram $telegram): void
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

        $project->update([
            'scoring' => $result,
            'fit_score' => $result['fit_score'],
            'category' => $result['category'],
            'ai_state' => 'scored',
        ]);

        $high = $result['fit_score'] >= $settings->int('threshold_high');
        $medium = $result['fit_score'] >= $settings->int('threshold_medium');
        $live = $project->source === 'freelancer';

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
