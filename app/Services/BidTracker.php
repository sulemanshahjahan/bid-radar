<?php

namespace App\Services;

use App\Models\BidSnapshot;
use App\Models\Project;

/**
 * Records bid counts over time and works out bids per hour.
 */
class BidTracker
{
    public function __construct(private readonly ScoreAdjuster $adjuster) {}

    /** Store new bid stats on the project, snapshot them, and re-adjust the score. */
    public function record(Project $project, int $bidCount, ?float $bidAvg): void
    {
        $details = $project->details ?? [];
        $details['bid_count'] = $bidCount;
        $details['bid_avg'] = $bidAvg;
        $project->forceFill(['details' => $details])->save();

        BidSnapshot::query()->create([
            'project_id' => $project->id,
            'bid_count' => $bidCount,
            'bid_avg' => $bidAvg,
            'recorded_at' => now(),
        ]);

        $this->adjuster->apply($project);
    }

    /**
     * Bids per hour: from snapshots over the last 2 hours when they span at
     * least 10 minutes, otherwise total bids divided by hours since posting.
     */
    public static function perHour(Project $project): ?float
    {
        $bids = $project->detail('bid_count');
        if ($bids === null) {
            return null;
        }

        $snaps = BidSnapshot::query()
            ->where('project_id', $project->id)
            ->where('recorded_at', '>=', now()->subHours(2))
            ->orderBy('recorded_at')
            ->get(['bid_count', 'recorded_at']);

        if ($snaps->count() >= 2) {
            $first = $snaps->first();
            $last = $snaps->last();
            $hours = $first->recorded_at->diffInSeconds($last->recorded_at) / 3600;
            if ($hours >= 10 / 60) {
                return round(max(0, $last->bid_count - $first->bid_count) / $hours, 1);
            }
        }

        $posted = $project->posted_at ?? $project->created_at;
        $hours = max(0.25, $posted->diffInSeconds(now()) / 3600);

        return round((int) $bids / $hours, 1);
    }
}
