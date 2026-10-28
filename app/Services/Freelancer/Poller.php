<?php

namespace App\Services\Freelancer;

use App\Jobs\ScoreProject;
use App\Models\ApiCall;
use App\Models\Project;
use App\Services\BidTracker;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fetches new active projects, runs the rules filter, and queues scoring.
 *
 * Gates (hard rule 2): FREELANCER_POLLING_ENABLED=true (written permission)
 * is required for any request. Scheduled runs also need the Settings toggle
 * on and Telegram /pause off.
 */
class Poller
{
    public const LAST_POLL = 'freelancer:last_poll_at';

    public const LAST_SUCCESS = 'freelancer:last_success_at';

    public const LAST_BID_REFRESH = 'freelancer:last_bid_refresh_at';

    /** The extra bid-stats call stays at most once every 2 minutes, however often new projects are searched. */
    public const BID_REFRESH_SECONDS = 120;

    private const SKILL_IDS = 'freelancer:skill_ids';

    /** Projects whose bid counts are still worth following. */
    private const TRACKED_STATUSES = ['new', 'drafted', 'bid_placed'];

    public function __construct(
        private readonly FreelancerClient $client,
        private readonly RulesFilter $rules,
        private readonly Settings $settings,
        private readonly BidTracker $bids,
    ) {}

    /**
     * @return array{status: string, message: string, projects?: list<array{id: int, title: string, result: string}>}
     */
    public function poll(bool $scheduled = true, bool $dryRun = false): array
    {
        if (config('bidradar.freelancer.polling_enabled') !== true) {
            return ['status' => 'off', 'message' => 'FREELANCER_POLLING_ENABLED is false; no request sent.'];
        }
        if ($scheduled && ! $this->settings->pollingActive()) {
            return ['status' => 'off', 'message' => 'Polling is switched off in Settings.'];
        }
        if ($scheduled && $this->settings->get('paused')) {
            return ['status' => 'paused', 'message' => 'Paused from Telegram.'];
        }
        if ($until = $this->client->backoffUntil()) {
            return ['status' => 'backoff', 'message' => 'Backing off until '.date('H:i:s', $until).'.'];
        }

        $interval = max(30, $this->settings->int('poll_interval_seconds'));
        $last = (int) Cache::get(self::LAST_POLL, 0);
        if ($scheduled && now()->getTimestamp() - $last < $interval - 10) {
            return ['status' => 'waiting', 'message' => 'Next poll not due yet.'];
        }
        if (! $dryRun) {
            Cache::forever(self::LAST_POLL, now()->getTimestamp());
        }

        try {
            $jobIds = $this->skillIds();
            if ($jobIds === []) {
                return ['status' => 'error', 'message' => 'None of the target skills matched a Freelancer skill.'];
            }

            $since = (int) Cache::get(self::LAST_SUCCESS, now()->subHour()->getTimestamp()) - 300;
            $data = $this->client->activeProjects(array_values($jobIds), $since);
        } catch (FreelancerException $e) {
            Log::warning('freelancer poll skipped', ['error' => $e->getMessage()]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        $seenIds = array_map(fn ($p) => (int) ($p['id'] ?? 0), $data['projects']);
        $known = Project::query()->whereIn('freelancer_id', $seenIds)->get()->keyBy('freelancer_id');

        $report = [];
        $queued = 0;
        foreach ($data['projects'] as $raw) {
            if (! isset($raw['id'])) {
                continue;
            }
            if ($known->has((int) $raw['id'])) {
                // Already stored: the search result carries fresh bid stats for free.
                $this->trackKnown($known->get((int) $raw['id']), $raw, $dryRun);

                continue;
            }

            $mapped = ProjectMapper::map($raw, $data['users'][(string) ($raw['owner_id'] ?? '')] ?? null);
            $reason = $this->rules->reject($mapped);
            $report[] = ['id' => $mapped['freelancer_id'], 'title' => $mapped['title'], 'result' => $reason ?? 'PASS -> scoring'];

            if ($dryRun) {
                continue;
            }

            $project = Project::query()->create([
                'source' => 'freelancer',
                'freelancer_id' => $mapped['freelancer_id'],
                'url' => $mapped['url'],
                'title' => $mapped['title'],
                'description' => $mapped['description'],
                'details' => $mapped['details'],
                'posted_at' => $mapped['posted_at'] ? date('Y-m-d H:i:s', $mapped['posted_at']) : now(),
                'ai_state' => $reason === null ? 'queued' : 'filtered',
                'filter_reason' => $reason,
            ]);

            if ($reason === null) {
                $this->bids->record($project, (int) $mapped['details']['bid_count'], $mapped['details']['bid_avg']);
                ScoreProject::dispatch($project->id);
                $queued++;
            }
        }

        if (! $dryRun) {
            Cache::forever(self::LAST_SUCCESS, now()->getTimestamp());
            $this->recordPollCounts($data, count($report), $known->count());
            $this->refreshTrackedBids($seenIds);
        }

        return [
            'status' => 'ok',
            'message' => sprintf('%d new, %d sent to scoring%s.', count($report), $dryRun ? 0 : $queued, $dryRun ? ' (dry run, nothing saved)' : ''),
            'projects' => $report,
        ];
    }

    /**
     * Counts for this poll on its api_calls row. "page_full" (returned = limit) is the truncation
     * signal: total_count is stored as reported but is not a reliable total.
     *
     * @param  array{projects: list<array<string, mixed>>, total_count?: int|null, call_id?: int|null}  $data
     */
    private function recordPollCounts(array $data, int $new, int $known): void
    {
        if (empty($data['call_id'])) {
            return;
        }

        $limit = (int) config('bidradar.freelancer.page_limit');
        $returned = count($data['projects']);
        ApiCall::query()->whereKey($data['call_id'])->update(['meta' => json_encode([
            'returned' => $returned,
            'total_count' => $data['total_count'] ?? null,
            'new' => $new,
            'known' => $known,
            'limit' => $limit,
            'page_full' => $limit > 0 && $returned >= $limit,
        ])]);
    }

    /** @param array<string, mixed> $raw */
    private function trackKnown(Project $project, array $raw, bool $dryRun): void
    {
        if ($dryRun || ! $this->isTracked($project)) {
            return;
        }

        $avg = data_get($raw, 'bid_stats.bid_avg');
        $this->bids->record($project, (int) data_get($raw, 'bid_stats.bid_count', 0), is_numeric($avg) && (float) $avg > 0 ? (float) $avg : null);
    }

    /**
     * One extra request (at most every BID_REFRESH_SECONDS): current bid stats for open projects from the last
     * 24 hours that this poll's search did not return. A failure here never fails the poll.
     *
     * @param  list<int>  $alreadySeen
     */
    private function refreshTrackedBids(array $alreadySeen): void
    {
        if (now()->getTimestamp() - (int) Cache::get(self::LAST_BID_REFRESH, 0) < self::BID_REFRESH_SECONDS - 10) {
            return;
        }
        Cache::forever(self::LAST_BID_REFRESH, now()->getTimestamp());

        $tracked = Project::query()
            ->where('source', 'freelancer')
            ->whereNotNull('freelancer_id')
            ->whereNotIn('freelancer_id', $alreadySeen)
            ->whereNull('purged_at')
            ->where('ai_state', '!=', 'filtered')
            ->whereIn('status', self::TRACKED_STATUSES)
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(50)
            ->get()
            ->keyBy('freelancer_id');

        if ($tracked->isEmpty()) {
            return;
        }

        try {
            $stats = $this->client->bidStats($tracked->keys()->map(fn ($id) => (int) $id)->all());
        } catch (FreelancerException $e) {
            Log::warning('freelancer bid refresh skipped', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($stats as $id => $s) {
            if ($project = $tracked->get($id)) {
                $this->bids->record($project, $s['bid_count'], $s['bid_avg']);
            }
        }
    }

    private function isTracked(Project $project): bool
    {
        return $project->purged_at === null && $project->ai_state !== 'filtered' && in_array($project->status, self::TRACKED_STATUSES, true);
    }

    /**
     * Freelancer job IDs for the target skills, looked up once per skill list.
     *
     * @return array<string, int>
     */
    public function skillIds(bool $refresh = false): array
    {
        $names = $this->settings->list('target_skills');
        $key = self::SKILL_IDS.':'.md5(implode('|', $names));

        if (! $refresh && is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $ids = $this->client->jobIds($names);
        Cache::forever($key, $ids);

        return $ids;
    }

    /** @return list<string> Target skills that did not match a Freelancer skill name. */
    public function unmatchedSkills(): array
    {
        $names = $this->settings->list('target_skills');
        $cached = Cache::get(self::SKILL_IDS.':'.md5(implode('|', $names)));
        if (! is_array($cached)) {
            return [];
        }

        $found = array_map('mb_strtolower', array_keys($cached));

        return array_values(array_filter($names, fn ($n) => ! in_array(mb_strtolower($n), $found, true)));
    }

    /** @return array<string, int>|null */
    public function cachedSkillIds(): ?array
    {
        $names = $this->settings->list('target_skills');
        $cached = Cache::get(self::SKILL_IDS.':'.md5(implode('|', $names)));

        return is_array($cached) ? $cached : null;
    }
}
