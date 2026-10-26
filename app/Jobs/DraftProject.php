<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\CostTracker;
use App\Services\Drafting\Drafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DraftProject implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  bool  $regenerate  Only an explicit Regenerate may draft a project that already has a draft.
     */
    public function __construct(
        public readonly int $projectId,
        public readonly bool $regenerate = false,
        public readonly ?string $instruction = null,
    ) {}

    public function handle(Drafter $drafter, CostTracker $costs): void
    {
        $project = Project::query()->find($this->projectId);
        if ($project === null || $project->purged_at !== null) {
            return;
        }

        // Never draft the same project twice unless Regenerate was tapped.
        if (! $this->regenerate && $project->drafts()->exists()) {
            $project->update(['ai_state' => 'drafted']);

            return;
        }

        if ($costs->capReached()) {
            $project->update([
                'ai_state' => 'cap_reached',
                'ai_error' => sprintf('Daily AI spend cap reached ($%.2f). Drafting stops until tomorrow, or raise the cap in Settings.', $costs->dailyCap()),
            ]);

            return;
        }

        $project->update(['ai_state' => 'drafting', 'ai_error' => null]);

        try {
            $drafter->draft($project, $this->instruction);
        } catch (Throwable $e) {
            $project->update(['ai_state' => 'failed', 'ai_error' => 'Drafting failed: '.$e->getMessage()]);

            return;
        }

        $project->update([
            'ai_state' => 'drafted',
            'status' => $project->status === 'new' ? 'drafted' : $project->status,
        ]);
    }
}
