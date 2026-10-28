<?php

namespace App\Console\Commands;

use App\Models\ApiCall;
use App\Models\BidSnapshot;
use App\Models\DailyStat;
use App\Models\Project;
use App\Services\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily counts that outlive the 24-hour purge. Counts only: no project IDs, titles, text or budgets.
 *
 * Runs at 00:05 UTC for the previous day: the hourly purge only removes rows created more than
 * 24 hours ago, so every project from that day is still there. Bid counts are as last seen at run time.
 */
#[Signature('bidradar:daily-stats {--date= : Day to count (Y-m-d, UTC); default yesterday}')]
#[Description('Store daily discovery, filter, score, alert and crowding counts')]
class DailyStats extends Command
{
    /** "Crowded" for the crowding counts, and the early-bids cut-off measured 2 minutes after posting. */
    private const CROWDED = 100;

    private const EARLY_BIDS = 30;

    public function handle(Settings $settings): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date'), 'UTC')->startOfDay() : now('UTC')->subDay()->startOfDay();
        $high = $settings->int('threshold_high');
        $medium = $settings->int('threshold_medium');

        $projects = Project::query()->where('source', 'freelancer')
            ->where('created_at', '>=', $day)->where('created_at', '<', $day->copy()->addDay())
            ->withCount('drafts')->get();

        $counts = [];
        $add = function (string $metric, string $key) use (&$counts) {
            $counts[$metric][$key] = ($counts[$metric][$key] ?? 0) + 1;
        };
        $band = fn (?int $score) => match (true) {
            $score === null => 'unscored', $score >= $high => 'high', $score >= $medium => 'medium', default => 'low',
        };

        foreach ($projects as $p) {
            $add('discovered', 'total');
            $add('filter', self::reason($p->filter_reason));
            foreach ((array) $p->detail('skills', []) as $skill) {
                $add('skill', (string) $skill);
                if ($p->fit_score !== null && $p->fit_score >= $medium) {
                    $add('skill_medium_plus', (string) $skill);
                }
            }
            if ($p->ai_state === 'filtered') {
                continue;
            }

            $add('band', $band($p->fit_score));
            $add('base_band', $band(is_numeric($p->score('fit_score')) ? (int) $p->score('fit_score') : null));
            if ($code = $p->decision['decision'] ?? null) {
                $add('decision', $code);
            }
            if ($p->alerted_at !== null) {
                $add('alert', ($p->decision['alert']['silent'] ?? null) === false ? 'loud' : 'quiet_or_silent');
            }
            if ($p->drafts_count > 0) {
                $add('drafted', 'total');
            }

            $latest = $p->detail('bid_count');
            if ($latest !== null) {
                $bucket = (int) $latest >= self::CROWDED ? '100+' : ((int) $latest >= 50 ? '50-99' : '0-49');
                $add('latest_bids', 'all:'.$bucket);
                if ($p->alerted_at !== null) {
                    $add('latest_bids', 'alerted:'.$bucket);
                }
                $early = $this->bidsAt($p, 120);
                if ($early !== null) {
                    $add('bids_2min_vs_latest', ($early >= self::EARLY_BIDS ? '30+' : '<30').'|'.((int) $latest >= self::CROWDED ? 'latest100+' : 'latest<100'));
                }
            }
        }

        $counts['my_bids']['placed'] = Project::query()->where('bid_placed_at', '>=', $day)->where('bid_placed_at', '<', $day->copy()->addDay())->count();

        $polls = ApiCall::query()->where('provider', 'freelancer')->where('purpose', 'poll')
            ->where('created_at', '>=', $day)->where('created_at', '<', $day->copy()->addDay())->get(['status', 'meta']);
        $counts['polls'] = [
            'ok' => $polls->where('status', 'ok')->count(),
            'error' => $polls->where('status', '!=', 'ok')->count(),
            'page_full' => $polls->filter(fn (ApiCall $c) => ($c->meta['page_full'] ?? false) === true)->count(),
        ];

        DB::transaction(function () use ($day, $counts) {
            DailyStat::query()->whereDate('date', $day->toDateString())->delete();
            foreach ($counts as $metric => $keys) {
                foreach ($keys as $key => $value) {
                    DailyStat::query()->create(['date' => $day->toDateString(), 'metric' => $metric, 'key' => mb_substr((string) $key, 0, 255), 'value' => $value]);
                }
            }
        });

        $this->info(sprintf('Stored %d counts for %s (%d projects).', array_sum(array_map('count', $counts)), $day->toDateString(), $projects->count()));

        return self::SUCCESS;
    }

    /** Filter reason without the numbers that come from the project ("Too many bids (123)" -> "Too many bids"). */
    public static function reason(?string $reason): string
    {
        if ($reason === null) {
            return 'passed';
        }

        return (string) preg_replace(
            ['/^Too many bids \(\d+\)$/', '/^Budget too low \(.*\)$/', '/^New build with \d+ bids .*/', '/^Posted more than .*/'],
            ['Too many bids', 'Budget too low', 'New build over bid limit', 'Too old'],
            $reason,
        );
    }

    /** Last bid count recorded at or before $seconds after posting; null when the first record is later. */
    private function bidsAt(Project $project, int $seconds): ?int
    {
        if ($project->posted_at === null) {
            return null;
        }
        $cutoff = $project->posted_at->copy()->addSeconds($seconds);
        $first = BidSnapshot::query()->where('project_id', $project->id)->orderBy('recorded_at')->orderBy('id')->first();
        if ($first === null || $first->recorded_at->gt($cutoff)) {
            return null;
        }

        return BidSnapshot::query()->where('project_id', $project->id)->where('recorded_at', '<=', $cutoff)
            ->orderByDesc('recorded_at')->orderByDesc('id')->value('bid_count');
    }
}
