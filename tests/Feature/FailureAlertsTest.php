<?php

namespace Tests\Feature;

use App\Jobs\ScoreProject;
use App\Services\Claude\ClaudeException;
use App\Services\FailureAlerts;
use App\Services\Freelancer\FreelancerClient;
use App\Services\Freelancer\Poller;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailureAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1793000700));
        config([
            'bidradar.telegram.bot_token' => '123:abc',
            'bidradar.telegram.chat_id' => '555',
            'bidradar.freelancer.polling_enabled' => true,
            'bidradar.freelancer.oauth_token' => 'test-token',
        ]);
    }

    private function telegramSends(string $needle): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.telegram.org') && str_contains($r['text'] ?? '', $needle)));
    }

    private function billingError(): ClaudeException
    {
        return new ClaudeException('Claude API error 400: invalid_request_error', 400, null, 'invalid_request_error',
            '{"status":400,"body":{"error":{"type":"invalid_request_error","message":"Your credit balance is too low to access the Anthropic API."}}}');
    }

    // --- Claude billing -------------------------------------------------------

    public function test_a_claude_billing_error_sends_one_loud_alert_per_hour(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $project = $this->makeProject();
        $this->claude->fail($this->billingError())->fail($this->billingError())->fail($this->billingError());

        ScoreProject::dispatchSync($project->id);
        ScoreProject::dispatchSync($project->id);

        $this->assertSame('failed', $project->fresh()->ai_state);
        $this->assertSame(1, $this->telegramSends('Claude credit problem'), 'Second failure within the hour is not re-sent.');
        Http::assertSent(fn (Request $r) => str_contains($r['text'] ?? '', 'Claude credit problem') && $r['disable_notification'] === false);

        Carbon::setTestNow(now()->addMinutes(61));
        ScoreProject::dispatchSync($project->id);
        $this->assertSame(2, $this->telegramSends('Claude credit problem'));
    }

    public function test_billing_is_recognised_by_type_status_or_message(): void
    {
        $this->assertTrue($this->billingError()->isBilling());
        $this->assertTrue((new ClaudeException('x', 400, null, 'billing_error'))->isBilling());
        $this->assertTrue((new ClaudeException('x', 402))->isBilling());
        $this->assertFalse((new ClaudeException('x', 529, null, 'overloaded_error', 'Overloaded'))->isBilling());
    }

    public function test_other_claude_errors_do_not_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $project = $this->makeProject();
        $this->claude->fail(new ClaudeException('Claude API error 529: overloaded_error', 529, null, 'overloaded_error', 'Overloaded'));

        ScoreProject::dispatchSync($project->id);

        Http::assertNothingSent();
    }

    // --- Freelancer token -----------------------------------------------------

    public function test_a_rejected_token_pauses_polling_for_an_hour_and_alerts_once(): void
    {
        app(Settings::class)->set(['polling_enabled' => true]);
        Queue::fake();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'www.freelancer.com/api/projects/0.1/jobs/*' => Http::response(file_get_contents(base_path('tests/fixtures/freelancer/jobs.json'))),
            'www.freelancer.com/api/projects/0.1/projects/active/*' => Http::response(['status' => 'error', 'message' => 'Invalid token'], 401),
        ]);

        $result = app(Poller::class)->poll();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('paused for 60 min', $result['message']);
        $this->assertSame(1, $this->telegramSends('Freelancer token rejected'));

        // For the next hour no request goes to Freelancer.
        Carbon::setTestNow(now()->addMinutes(59));
        $this->assertSame('backoff', app(Poller::class)->poll()['status']);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'projects/active')));
    }

    // --- Stalled polling ------------------------------------------------------

    public function test_no_successful_poll_for_30_minutes_alerts_once_per_hour(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(Settings::class)->set(['polling_enabled' => true]);
        $alerts = app(FailureAlerts::class);
        $freelancer = app(FreelancerClient::class);

        Cache::forever(Poller::LAST_SUCCESS, now()->subMinutes(10)->getTimestamp());
        $alerts->checkPolling($freelancer); // starts the "active since" clock
        $this->assertSame(0, $this->telegramSends('no successful poll'));

        Carbon::setTestNow(now()->addMinutes(31)); // 31 min since polling was on and last succeeded
        $this->assertTrue($alerts->checkPolling($freelancer));
        $this->assertFalse($alerts->checkPolling($freelancer), 'Not again within the hour.');
        $this->assertSame(1, $this->telegramSends('no successful poll for 31 minutes'));
    }

    public function test_no_stall_alert_when_polling_is_off_or_paused(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Cache::forever(Poller::LAST_SUCCESS, now()->subHours(3)->getTimestamp());
        $alerts = app(FailureAlerts::class);
        $freelancer = app(FreelancerClient::class);

        app(Settings::class)->set(['polling_enabled' => false]);
        $this->assertFalse($alerts->checkPolling($freelancer));

        app(Settings::class)->set(['polling_enabled' => true, 'paused' => true]);
        $this->assertFalse($alerts->checkPolling($freelancer));

        Http::assertNothingSent();
    }

    public function test_a_newly_enabled_poller_gets_30_minutes_before_a_stall_alert(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(Settings::class)->set(['polling_enabled' => true]);
        $alerts = app(FailureAlerts::class);
        $freelancer = app(FreelancerClient::class);

        $this->assertFalse($alerts->checkPolling($freelancer)); // never polled: clock starts now
        Carbon::setTestNow(now()->addMinutes(29));
        $this->assertFalse($alerts->checkPolling($freelancer));
        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertTrue($alerts->checkPolling($freelancer));
    }

    public function test_the_watchdog_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('bidradar:watchdog')->assertSuccessful();
    }
}
