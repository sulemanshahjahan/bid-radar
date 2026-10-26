<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\CostTracker;
use App\Services\DraftGate;
use App\Services\Drafting\Drafter;
use App\Services\Scoring\Scorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('bidradar:redraft {project : Project ID} {--anyway : Draft even if the project is filtered}')]
#[Description('Re-score one project and draft it again, printing the warning, price, delivery and proposal')]
class RedraftProject extends Command
{
    public function handle(Scorer $scorer, Drafter $drafter, DraftGate $gate, CostTracker $costs): int
    {
        $project = Project::query()->find($this->argument('project'));
        if ($project === null || $project->purged_at !== null || ! $project->description) {
            $this->error('Project not found, or its data was already purged (24h rule).');

            return self::FAILURE;
        }

        try {
            $result = $scorer->score($project);
        } catch (Throwable $e) {
            $this->error('Scoring failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $project->update(['scoring' => $result, 'fit_score' => $result['fit_score'], 'category' => $result['category'], 'ai_state' => 'scored']);

        $this->line("Score: {$result['fit_score']} · ".config('bidradar.categories.'.$result['category'])
            .' · estimated '.($result['estimated_hours'] ?? '?').' hours');

        if ($message = $gate->message($project)) {
            $this->warn($message);
            if (! $this->option('anyway')) {
                $this->line('Not drafted. Run again with --anyway to draft it anyway.');

                return self::SUCCESS;
            }
        }

        if ($costs->capReached()) {
            $this->error('Daily AI spend cap reached.');

            return self::FAILURE;
        }

        try {
            $draft = $drafter->draft($project, null);
        } catch (Throwable $e) {
            $this->error('Drafting failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $project->update(['ai_state' => 'drafted', 'status' => $project->status === 'new' ? 'drafted' : $project->status]);

        $this->newLine();
        $this->line("Bid: {$draft->currency} ".(float) $draft->bid_amount.($draft->is_hourly ? '/hr' : '')." ({$draft->price_reason})");
        $this->line('Delivery: '.($draft->delivery_days ? $draft->delivery_days.' days' : 'not set'));
        foreach ((array) $draft->warnings as $warning) {
            $this->warn('Warning: '.$warning);
        }
        foreach ((array) $draft->check_failures as $failure) {
            $this->error('Check failed: '.$failure);
        }
        if ($draft->milestones) {
            $this->line("Milestones:\n".$draft->milestonesText());
        }
        $this->newLine();
        $this->line($draft->proposal_text);

        return self::SUCCESS;
    }
}
