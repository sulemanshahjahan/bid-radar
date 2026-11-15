<?php

namespace App\Services;

use App\Jobs\DraftProject;
use App\Jobs\ScoreProject;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A "database is locked" error can still reach a project outside the job's own retry, e.g. while
 * the queue worker reserves or releases the job row. The project is then marked failed and never
 * alerted, although nothing is wrong with it. The watchdog runs this every minute: recent live
 * projects that failed only because of a lock are queued again, at most MAX_REQUEUES times each.
 */
class LockRecovery
{
    public const MAX_REQUEUES = 3;

    /** Only fresh projects: an old one is worthless to bid on, so leave it failed. */
    public const WINDOW_MINUTES = 30;

    /** @return list<int> ids of the projects queued again */
    public function requeue(): array
    {
        $requeued = [];
        $candidates = Project::query()
            ->where('ai_state', 'failed')
            ->whereNull('purged_at')
            ->where('ai_error', 'like', '%database%locked%')
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->limit(20)
            ->get();

        foreach ($candidates as $project) {
            try {
                $key = 'lock-requeue:'.$project->id;
                $count = (int) Cache::get($key, 0);
                if ($count >= self::MAX_REQUEUES) {
                    continue;
                }
                Cache::put($key, $count + 1, now()->addHours(2));

                $drafting = str_starts_with((string) $project->ai_error, 'Drafting failed');
                $project->update(['ai_state' => 'queued', 'ai_error' => null]);
                if ($drafting) {
                    // Scoring already succeeded: only the automatic (fast) draft is missing.
                    DraftProject::dispatch($project->id, alert: $project->alerted_at !== null, fast: true);
                } else {
                    ScoreProject::dispatch($project->id);
                }
                $requeued[] = $project->id;
            } catch (Throwable $e) {
                // Still locked: the next watchdog run tries again.
                Log::warning('Lock recovery could not requeue project '.$project->id.': '.$e->getMessage());
            }
        }

        return $requeued;
    }
}
