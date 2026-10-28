<?php

namespace App\Console\Commands;

use App\Models\ApiCall;
use App\Models\BidSnapshot;
use App\Models\DailyStat;
use App\Models\Project;
use App\Services\Opportunity\ProfileEvidence;
use App\Services\Opportunity\ShadowRanking;
use App\Services\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts that outlive the 24-hour purge, stored per UTC hour (a day is the sum of its hours).
 * Counts only: no project IDs, titles, text or budgets.
 *
 * Why per hour: the hourly purge deletes rows once they are 24 hours old, so a whole calendar day is
 * never both finished and intact. An hour H is intact until H+24h. Each hour is counted once, on the
 * first run at least COUNT_AFTER_HOURS after it started (bid counts are then close to final), and no
 * later than SAFE_MINUTES. Hourly runs give four chances. A recount replaces the hour (no double
 * counting). An hour that passes SAFE_MINUTES uncounted gets meta/missed = 1 instead of disappearing.
 */
#[Signature('bidradar:daily-stats {--hour= : Recount one closed hour, "Y-m-d H" (UTC), while its data is intact}')]
#[Description('Store hourly counts of discovery, filters, scores, alerts and crowding (counts only)')]
class DailyStats extends Command
{
    public const COUNT_AFTER_HOURS = 20;

    /** Last safe moment after the hour starts: the purge at H+24:00 starts deleting it. */
    public const SAFE_MINUTES = 24 * 60 - 5;

    /** How far back to look for hours that were never counted. */
    private const LOOKBACK_HOURS = 7 * 24;

    private const CROWDED = 100;

    private const EARLY_BIDS = 30;

    public function handle(Settings $settings): int
    {
        $now = now('UTC');

        if ($this->option('hour')) {
            $hour = Carbon::createFromFormat('Y-m-d H', (string) $this->option('hour'), 'UTC')->startOfHour();
            if ($now->lt($hour->copy()->addHour()) || $now->gte($hour->copy()->addMinutes(self::SAFE_MINUTES))) {
                $this->error("Hour {$hour->format('Y-m-d H:00')} is still open or its data may already be purged; not recounted (existing counts kept).");

                return self::FAILURE;
            }
            $this->countHour($hour, $settings);

            return self::SUCCESS;
        }

        // Hours from before this job first ran are not "missed": only flag from the first hour it recorded.
        $first = DailyStat::query()->where('metric', 'meta')->orderBy('date')->orderBy('hour')->first();
        $trackedFrom = $first ? Carbon::parse($first->date->toDateString(), 'UTC')->setHour($first->hour) : null;
        $counted = $missed = 0;
        for ($h = $now->copy()->startOfHour()->subHours(self::LOOKBACK_HOURS); $h->lte($now->copy()->subHours(self::COUNT_AFTER_HOURS)); $h->addHour()) {
            if ($this->hasMeta($h)) {
                continue;
            }
            if ($now->gte($h->copy()->addMinutes(self::SAFE_MINUTES))) {
                // Too late to count without losing rows: flag it so the gap is visible.
                if ($trackedFrom !== null && $h->gte($trackedFrom)) {
                    $this->store($h, ['meta' => ['missed' => 1]]);
                    $missed++;
                }

                continue;
            }
            $this->countHour($h->copy(), $settings);
            $counted++;
        }

        $this->info("Counted {$counted} hour(s), flagged {$missed} missed.");

        return self::SUCCESS;
    }

    private function hasMeta(Carbon $hour): bool
    {
        return DailyStat::query()->whereDate('date', $hour->toDateString())->where('hour', $hour->hour)->where('metric', 'meta')->exists();
    }

    private function countHour(Carbon $hour, Settings $settings): void
    {
        $from = $hour->copy();
        $to = $hour->copy()->addHour();
        $high = $settings->int('threshold_high');
        $medium = $settings->int('threshold_medium');

        $projects = Project::query()->where('source', 'freelancer')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->withCount('drafts')->get();

        $counts = [];
        $add = function (string $metric, string $key) use (&$counts) {
            $counts[$metric][$key] = ($counts[$metric][$key] ?? 0) + 1;
        };
        $band = fn (?int $score) => match (true) {
            $score === null => 'unscored', $score >= $high => 'high', $score >= $medium => 'medium', default => 'low',
        };

        $profile = null;
        if (ShadowRanking::enabled()) {
            try {
                $profile = ProfileEvidence::fromSettings($settings);
            } catch (Throwable $e) {
                Log::warning('opportunity counts skipped', ['error' => $e::class]);
            }
        }

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
            // Shadow mode: opportunity class (as at scoring time) against the band of the score BidRadar acted on.
            if ($profile !== null && $p->scored_at !== null && is_array($p->scoring)) {
                $class = app(ShadowRanking::class)->safeEvaluate($p, $profile, $p->scored_at)['opportunity']?->class ?? 'error';
                $add('opportunity', $class.'|'.$band(ShadowRanking::decisionScore($p)));
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

        $counts['my_bids']['placed'] = Project::query()->where('bid_placed_at', '>=', $from)->where('bid_placed_at', '<', $to)->count();

        $polls = ApiCall::query()->where('provider', 'freelancer')->where('purpose', 'poll')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->get(['status', 'meta']);
        $counts['polls'] = [
            'ok' => $polls->where('status', 'ok')->count(),
            'error' => $polls->where('status', '!=', 'ok')->count(),
            'page_full' => $polls->filter(fn (ApiCall $c) => ($c->meta['page_full'] ?? false) === true)->count(),
        ];
        $counts['meta'] = ['counted_at' => now()->getTimestamp(), 'projects' => $projects->count()];

        $this->store($hour, $counts);
    }

    /**
     * Replace everything stored for this hour in one transaction.
     *
     * @param  array<string, array<string, int>>  $counts
     */
    private function store(Carbon $hour, array $counts): void
    {
        DB::transaction(function () use ($hour, $counts) {
            DailyStat::query()->whereDate('date', $hour->toDateString())->where('hour', $hour->hour)->delete();
            foreach ($counts as $metric => $keys) {
                foreach ($keys as $key => $value) {
                    DailyStat::query()->create([
                        'date' => $hour->toDateString(), 'hour' => $hour->hour,
                        'metric' => $metric, 'key' => mb_substr((string) $key, 0, 255), 'value' => $value,
                    ]);
                }
            }
        });
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
