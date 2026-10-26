<?php

namespace App\Services;

use App\Services\Claude\ClaudeException;
use App\Services\Freelancer\FreelancerClient;
use App\Services\Freelancer\Poller;
use App\Services\Telegram\Telegram;
use Illuminate\Support\Facades\Cache;

/**
 * Telegram alerts when BidRadar stops working: Claude billing errors, a rejected
 * Freelancer token, or no successful poll for 30 minutes while polling is on.
 * Each kind is sent at most once per hour, with sound (they need action).
 */
class FailureAlerts
{
    public const STALL_MINUTES = 30;

    private const ACTIVE_SINCE = 'watchdog:polling_active_since';

    public function __construct(
        private readonly Telegram $telegram,
        private readonly Settings $settings,
    ) {}

    public function claudeFailed(ClaudeException $e): bool
    {
        if (! $e->isBilling()) {
            return false;
        }

        return $this->send('claude_billing', "🚨 <b>BidRadar: Claude credit problem</b>\n"
            .'Claude refused a request for billing reasons (HTTP '.($e->httpStatus ?? '?')."). Scoring and drafting are stopped.\n"
            .'Add credit or turn on auto-reload in the Claude Console, then check Settings.');
    }

    public function freelancerTokenRejected(int $status): bool
    {
        return $this->send('freelancer_token', "🚨 <b>BidRadar: Freelancer token rejected</b>\n"
            ."Freelancer returned HTTP {$status}. The token is expired or revoked. Polling is paused for 1 hour.\n"
            .'Generate a new token at accounts.freelancer.com, Settings, Develop, then update FREELANCER_OAUTH_TOKEN in Forge.');
    }

    /** Run every minute from the scheduler. */
    public function checkPolling(FreelancerClient $freelancer): bool
    {
        if (! $this->settings->pollingActive() || $this->settings->get('paused')) {
            Cache::forget(self::ACTIVE_SINCE);

            return false;
        }

        Cache::add(self::ACTIVE_SINCE, now()->getTimestamp(), now()->addDays(30));
        $since = max((int) Cache::get(Poller::LAST_SUCCESS, 0), (int) Cache::get(self::ACTIVE_SINCE));
        $minutes = (int) floor((now()->getTimestamp() - $since) / 60);

        if ($minutes < self::STALL_MINUTES) {
            return false;
        }

        $last = Cache::get(Poller::LAST_SUCCESS);
        $backoff = $freelancer->backoffUntil();

        return $this->send('polling_stalled', "🚨 <b>BidRadar: no successful poll for {$minutes} minutes</b>\n"
            .'Last success: '.($last ? now()->setTimestamp($last)->diffForHumans() : 'never').".\n"
            .($backoff ? 'Backing off after an API error until '.date('H:i', $backoff)." (server time).\n" : '')
            .'Check that the Forge scheduler is running, and the last error in the logs.');
    }

    /** At most once per hour per kind. Not counted as sent if Telegram did not take it. */
    private function send(string $kind, string $html): bool
    {
        $key = 'failure_alert:'.$kind;
        if (! Cache::add($key, now()->getTimestamp(), now()->addHour())) {
            return false;
        }

        $sent = $this->telegram->send($html, [], silent: false, purpose: 'failure');
        if (! $sent) {
            Cache::forget($key);
        }

        return $sent;
    }
}
