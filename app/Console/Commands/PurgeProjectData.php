<?php

namespace App\Console\Commands;

use App\Models\BidSnapshot;
use App\Models\Project;
use App\Services\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Freelancer API Terms: project data is kept no longer than 24 hours.
 *
 * - Filtered, low-fit and untouched projects with no draft: the whole row is deleted.
 * - Everything else: brief, budget, client details and scoring are wiped; we keep
 *   only the project ID, URL, category, score, drafts, my bid, status and dates.
 */
#[Signature('bidradar:purge {--hours=24}')]
#[Description('Delete Freelancer project data older than 24 hours')]
class PurgeProjectData extends Command
{
    public function handle(Settings $settings): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));
        $medium = $settings->int('threshold_medium');

        $deleted = Project::query()
            ->where('created_at', '<', $cutoff)
            ->where('status', 'new')
            ->whereDoesntHave('drafts')
            ->where(fn ($q) => $q->where('ai_state', 'filtered')->orWhereNull('fit_score')->orWhere('fit_score', '<', $medium))
            ->delete();

        $wiped = 0;
        Project::query()
            ->where('created_at', '<', $cutoff)
            ->whereNull('purged_at')
            ->chunkById(200, function ($projects) use (&$wiped) {
                foreach ($projects as $project) {
                    $project->forceFill([
                        'title' => null,
                        'description' => null,
                        'details' => null,
                        'scoring' => null,
                        'filter_reason' => null,
                        'ai_error' => null,
                        'purged_at' => now(),
                    ])->save();
                    $wiped++;
                }
            });

        // Bid history is project data too.
        BidSnapshot::query()->where('recorded_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} projects, wiped data from {$wiped}.");

        return self::SUCCESS;
    }
}
