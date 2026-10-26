<?php

namespace App\Services\Freelancer;

use App\Jobs\ScoreProject;
use App\Models\Project;
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

    private const SKILL_IDS = 'freelancer:skill_ids';

    public function __construct(
        private readonly FreelancerClient $client,
        private readonly RulesFilter $rules,
        private readonly Settings $settings,
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

        $interval = max(1, $this->settings->int('poll_interval_minutes')) * 60;
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

        $known = Project::query()
            ->whereIn('freelancer_id', array_map(fn ($p) => (int) ($p['id'] ?? 0), $data['projects']))
            ->pluck('freelancer_id')->all();

        $report = [];
        $queued = 0;
        foreach ($data['projects'] as $raw) {
            if (! isset($raw['id']) || in_array((int) $raw['id'], $known, true)) {
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
                ScoreProject::dispatch($project->id);
                $queued++;
            }
        }

        if (! $dryRun) {
            Cache::forever(self::LAST_SUCCESS, now()->getTimestamp());
        }

        return [
            'status' => 'ok',
            'message' => sprintf('%d new, %d sent to scoring%s.', count($report), $dryRun ? 0 : $queued, $dryRun ? ' (dry run, nothing saved)' : ''),
            'projects' => $report,
        ];
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
