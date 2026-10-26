<?php

namespace App\Services\Freelancer;

use App\Services\CostTracker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Read-only Freelancer API client. It only ever sends GET requests: it never
 * bids, messages, or changes anything on the account (hard rule 1).
 *
 * On a 429 or 5xx it records an exponential backoff and throws; callers skip
 * the cycle. It never retries in a loop.
 */
class FreelancerClient
{
    private const BACKOFF_UNTIL = 'freelancer:backoff_until';

    private const BACKOFF_LEVEL = 'freelancer:backoff_level';

    public function __construct(private readonly CostTracker $log) {}

    /**
     * Active projects with full description, skills, upgrades and inline owner details.
     *
     * @param  list<int>  $jobIds
     * @return array{projects: list<array<string, mixed>>, users: array<string, array<string, mixed>>}
     */
    public function activeProjects(array $jobIds, ?int $fromTime = null): array
    {
        $result = $this->get('projects/0.1/projects/active/', 'poll', array_filter([
            'jobs[]' => $jobIds,
            'from_time' => $fromTime,
            'sort_field' => 'time_updated',
            'limit' => config('bidradar.freelancer.page_limit'),
            'full_description' => 'true',
            'job_details' => 'true',
            'upgrade_details' => 'true',
            'owner_info' => 'true',
            'user_details' => 'true',
            'user_status' => 'true',
            'user_employer_reputation' => 'true',
            'compact' => 'true',
        ], fn ($v) => $v !== null && $v !== []));

        $users = $result['users'] ?? [];
        if (is_array($users) && array_is_list($users)) {
            $users = collect($users)->keyBy(fn ($u) => (string) ($u['id'] ?? ''))->all();
        }

        return [
            'projects' => array_values($result['projects'] ?? []),
            'users' => is_array($users) ? $users : [],
        ];
    }

    /**
     * Look up job (skill) IDs by name.
     *
     * @param  list<string>  $names
     * @return array<string, int> name => id
     */
    public function jobIds(array $names): array
    {
        $result = $this->get('projects/0.1/jobs/', 'jobs', ['job_names[]' => array_values($names)]);

        $ids = [];
        foreach ((array) $result as $job) {
            if (isset($job['id'], $job['name'])) {
                $ids[$job['name']] = (int) $job['id'];
            }
        }

        return $ids;
    }

    public function backoffUntil(): ?int
    {
        $until = Cache::get(self::BACKOFF_UNTIL);

        return $until && $until > now()->getTimestamp() ? (int) $until : null;
    }

    /** @return mixed The `result` member of the response envelope. */
    private function get(string $path, string $purpose, array $query): mixed
    {
        $token = config('bidradar.freelancer.oauth_token');
        if (! $token) {
            throw new FreelancerException('FREELANCER_OAUTH_TOKEN is not set.');
        }

        $started = hrtime(true);
        try {
            $response = Http::baseUrl(config('bidradar.freelancer.base_url'))
                ->withHeaders(['freelancer-oauth-v1' => $token])
                ->acceptJson()
                ->timeout(config('bidradar.freelancer.timeout'))
                ->get($path, self::query($query));
        } catch (ConnectionException $e) {
            $this->log->logApi('freelancer', $purpose, false, null, self::ms($started), 'connection: '.$e->getMessage());
            $this->backOff();
            throw new FreelancerException('Freelancer API connection failed.', null, $e);
        }

        $ms = self::ms($started);

        if ($response->status() === 429 || $response->serverError()) {
            $this->log->logApi('freelancer', $purpose, false, $response->status(), $ms, self::errorText($response));
            $minutes = $this->backOff();
            throw new FreelancerException("Freelancer API returned {$response->status()}; backing off {$minutes} min.", $response->status());
        }

        if (! $response->successful() || $response->json('status') !== 'success') {
            $this->log->logApi('freelancer', $purpose, false, $response->status(), $ms, self::errorText($response));
            throw new FreelancerException('Freelancer API error '.$response->status().': '.self::errorText($response), $response->status());
        }

        $this->log->logApi('freelancer', $purpose, true, $response->status(), $ms);
        Cache::forget(self::BACKOFF_LEVEL);

        // Respect the published rate limit: if this window is used up, pause until it resets.
        if ($response->header('RateLimit-Remaining') === '0') {
            Cache::put(self::BACKOFF_UNTIL, now()->addMinute()->getTimestamp(), now()->addMinutes(2));
        }

        return $response->json('result');
    }

    /** Freelancer expects repeated keys (jobs[]=1&jobs[]=2), not PHP's jobs[0]=1. */
    private static function query(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            foreach ((array) $value as $v) {
                $parts[] = rawurlencode($key).'='.rawurlencode((string) $v);
            }
        }

        return implode('&', $parts);
    }

    /** @return int minutes backed off */
    private function backOff(): int
    {
        $level = (int) Cache::get(self::BACKOFF_LEVEL, 0) + 1;
        $minutes = min(2 ** $level, (int) config('bidradar.freelancer.backoff_max_minutes'));

        Cache::put(self::BACKOFF_LEVEL, $level, now()->addDay());
        Cache::put(self::BACKOFF_UNTIL, now()->addMinutes($minutes)->getTimestamp(), now()->addMinutes($minutes + 1));

        return $minutes;
    }

    private static function errorText(Response $response): string
    {
        return mb_substr((string) ($response->json('error_code') ?? $response->json('message') ?? $response->body()), 0, 300);
    }

    private static function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
